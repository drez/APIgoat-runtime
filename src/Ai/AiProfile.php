<?php

namespace ApiGoat\Ai;

/**
 * The resolved "who do we talk to, and how" for one tenant's AI calls.
 *
 * AiConfig is process-global and memoized; AiManifest is build-time. Neither
 * knows about tenants, and neither should: for every project that declares
 * with_ai and nothing else, this class is a thin view over them. A project
 * that needs per-company providers (apigmail: company_setting rows + a
 * secret_store key) registers a resolver — the same callable seam
 * with_notify uses — and everything the resolver leaves out falls through
 * the ladder below.
 *
 *   base_url   resolver → config('ollama_base_url') → env OLLAMA_BASE_URL → AiManifest::baseUrl()
 *   api_key    resolver → env OLLAMA_API_KEY → 'ollama'      (ollama; NEVER AiConfig::apiKey())
 *              resolver → AiConfig::apiKey()                 (openai)
 *              resolver → env ANTHROPIC_API_KEY              (anthropic)
 *   model      resolver → config('<provider>_model') → env <PROVIDER>_MODEL
 *   chat_model resolver → config('<provider>_chat_model') → env <PROVIDER>_CHAT_MODEL
 *              → AiManifest chat.llm_model (primary profile only)
 *              → model()  (reuse the task model — a second tag evicts the first
 *                          on a single-slot host)
 *              → 'hermes3:8b' (ollama) / model() (cloud providers)
 *   embed_model resolver → config('<provider>_embed_model') → env <PROVIDER>_EMBED_MODEL
 *              → AiManifest embed.model (primary profile only) → ''
 *   keep_alive resolver → AiManifest keep_alive → -1 (primary ollama) / null
 *   timeout / retries / throttle   resolver → AiManifest
 *
 * The ollama key ladder deliberately skips AiConfig::apiKey(): that row is the
 * operator's OpenAI key, and the default base URL is a LAN box. A cloud key
 * must never ride a request to a host chosen by a config row.
 *
 * Memoized per tenant; a cron loop that triages several companies in one
 * process calls reset() between jobs.
 */
final class AiProfile
{
    public const PROVIDERS = ['ollama', 'openai', 'anthropic'];
    public const POLICIES  = ['none', 'cloud_if_configured'];
    /** Free-form chat model when nothing else names one and the provider is a local Ollama. */
    public const DEFAULT_OLLAMA_CHAT_MODEL = 'hermes3:8b';

    /** @var callable|null fn(?int $idTenant): array */
    private static $resolver = null;

    /** @var array<string,self> */
    private static array $memo = [];

    private string $provider;
    private string $baseUrl;
    private string $model;
    private string $chatModel;
    private string $embedModel;
    private int $embedDimensions;
    /** @var int|string|null */
    private $keepAlive;
    private string $apiKey;
    private string $auth;
    private int $timeout;
    private int $retries;
    private float $throttle;
    /** @var array{input_per_m:float,output_per_m:float} */
    private array $prices;
    private string $fallbackPolicy;
    private string $promptVersion;
    private bool $isFallback;
    /** @var array<string,mixed> the resolver's raw `fallback` partial */
    private array $fallbackSpec;

    private function __construct()
    {
    }

    /**
     * Register (or clear, with null) the per-tenant resolver.
     *
     * The callable receives ?int $idTenant and returns a partial array with
     * any of: provider, base_url, model, chat_model, api_key, auth, timeout, retries,
     * throttle, prices{input_per_m,output_per_m}, fallback_policy,
     * prompt_version, fallback{provider,base_url,model,api_key,auth,prices}.
     * Registering clears the memo.
     */
    public static function setResolver(?callable $fn): void
    {
        self::$resolver = $fn;
        self::$memo = [];
    }

    /** Drop the per-tenant memo (call between jobs in a multi-tenant loop). */
    public static function reset(): void
    {
        self::$memo = [];
    }

    public static function forTenant(?int $idTenant): self
    {
        $key = $idTenant === null ? '' : (string) $idTenant;
        if (!isset(self::$memo[$key])) {
            $spec = [];
            if (self::$resolver !== null) {
                $r = (self::$resolver)($idTenant);
                $spec = \is_array($r) ? $r : [];
            }
            self::$memo[$key] = self::build($spec, false);
        }

        return self::$memo[$key];
    }

    /**
     * @param array<string,mixed> $spec
     * @param bool $fallback true when building the cloud fallback: the key
     *   then comes from $spec ONLY — no config row, no env — so a fallback
     *   can never quietly pick up the operator's key.
     */
    private static function build(array $spec, bool $fallback): self
    {
        $p = new self();
        $provider = \strtolower((string) ($spec['provider'] ?? ($fallback ? 'openai' : 'ollama')));
        $p->provider = \in_array($provider, self::PROVIDERS, true) ? $provider : 'ollama';
        $p->isFallback = $fallback;

        $p->baseUrl = self::str($spec['base_url'] ?? null) ?? self::defaultBaseUrl($p->provider);
        $p->model   = self::str($spec['model'] ?? null)
            ?? AiConfig::config($p->provider . '_model')
            ?? self::str(AiConfig::fromEnv(\strtoupper($p->provider) . '_MODEL'))
            ?? '';
        // The conversational model is a separate knob — but not a different
        // model by default. Two rungs were INSERTED below (4 and 5); none was
        // removed, so a project that names a chat model anywhere in rungs 1-3
        // keeps exactly what it had.
        $p->chatModel = self::str($spec['chat_model'] ?? null)                          // 1. resolver
            ?? AiConfig::config($p->provider . '_chat_model')                           // 2. config row
            ?? self::str(AiConfig::fromEnv(\strtoupper($p->provider) . '_CHAT_MODEL'))  // 3. env
            // 4. declared in HJSON as chat.llm_model — PRIMARY profile only. A
            //    cloud fallback must never inherit an Ollama tag as its OpenAI
            //    model name. NOTE chat.model is the Propel PhpName
            //    ("MailMessage"), never an LLM; it is never read here.
            ?? ($fallback ? null : self::str((AiManifest::chat() ?? [])['llm_model'] ?? null))
            // 5. reuse the task model rather than loading a second one: on a
            //    single-slot host a distinct chat tag evicts the pinned triage
            //    model on every question. str() returns null on '', so an
            //    empty task model still reaches rung 6.
            ?? self::str($p->model)
            // 6. terminal default, unchanged and still the last resort.
            ?? ($p->provider === 'ollama' ? self::DEFAULT_OLLAMA_CHAT_MODEL : $p->model);

        // Embeddings — same ladder shape. Nothing declared → '' / 0, which the
        // embed drivers turn into a loud permanent failure instead of posting
        // an empty model name and parsing whatever comes back.
        $embed = AiManifest::embed();
        $p->embedModel = self::str($spec['embed_model'] ?? null)
            ?? AiConfig::config($p->provider . '_embed_model')
            ?? self::str(AiConfig::fromEnv(\strtoupper($p->provider) . '_EMBED_MODEL'))
            ?? ($fallback ? null : self::str($embed['model']))
            ?? '';
        $specDims = isset($spec['embed_dimensions']) ? (int) $spec['embed_dimensions'] : 0;
        $p->embedDimensions = $specDims > 0 ? $specDims : (int) $embed['dimensions'];

        // Ollama's per-request model pin. keep_alive RESETS the model's expiry
        // on every request, so any call that omits it drops a pinned model from
        // "forever" to the daemon's 20-minute default — idle twenty minutes
        // after one chat answer and the next triage pays a cold load.
        if (\array_key_exists('keep_alive', $spec)) {
            $p->keepAlive = self::keepAliveValue($spec['keep_alive']);
        } else {
            $fromManifest = AiManifest::keepAlive();
            $p->keepAlive = $fromManifest !== null
                ? $fromManifest
                : (($p->provider === 'ollama' && !$fallback) ? -1 : null);
        }
        // SECURITY: the operator's cloud key (env / config row) only ever rides
        // to that provider's own endpoint. A resolver row naming openai /
        // anthropic with its own base_url but no api_key gets NO key — else a
        // tenant-editable endpoint (https://evil/v1) received the operator's
        // bearer on the first request.
        $ownEndpoint = $p->provider === 'ollama'
            || self::str($spec['base_url'] ?? null) === null
            || \rtrim($p->baseUrl, '/') === \rtrim(self::defaultBaseUrl($p->provider), '/');
        $p->apiKey = self::str($spec['api_key'] ?? null)
            ?? (($fallback || !$ownEndpoint) ? '' : self::defaultApiKey($p->provider));
        $p->auth = \in_array($spec['auth'] ?? null, ['bearer', 'x-api-key', 'none'], true)
            ? (string) $spec['auth']
            : ($p->provider === 'anthropic' ? 'x-api-key' : 'bearer');

        $p->timeout  = isset($spec['timeout']) ? (int) $spec['timeout'] : AiManifest::timeout();
        $p->retries  = isset($spec['retries']) ? (int) $spec['retries'] : AiManifest::retries();
        $p->throttle = isset($spec['throttle']) ? (float) $spec['throttle'] : AiManifest::throttleSeconds();

        $prices = \is_array($spec['prices'] ?? null) ? $spec['prices'] : [];
        $p->prices = [
            'input_per_m'  => (float) ($prices['input_per_m'] ?? 0.0),
            'output_per_m' => (float) ($prices['output_per_m'] ?? 0.0),
        ];

        $policy = (string) ($spec['fallback_policy'] ?? 'none');
        $p->fallbackPolicy = \in_array($policy, self::POLICIES, true) ? $policy : 'none';
        $p->promptVersion  = self::str($spec['prompt_version'] ?? null) ?? 'v1';
        $p->fallbackSpec   = \is_array($spec['fallback'] ?? null) ? $spec['fallback'] : [];

        return $p;
    }

    private static function str($v): ?string
    {
        return \is_string($v) && \trim($v) !== '' ? $v : null;
    }

    /**
     * Ollama accepts keep_alive as a number of seconds (-1 = forever) or a
     * duration string ("30m"). Anything else means "do not send one".
     *
     * @param mixed $v
     * @return int|string|null
     */
    private static function keepAliveValue($v)
    {
        if (\is_int($v)) {
            return $v;
        }
        if (\is_float($v)) {
            return (int) $v;
        }

        return self::str($v);
    }

    private static function defaultBaseUrl(string $provider): string
    {
        switch ($provider) {
            case 'openai':
                return 'https://api.openai.com/v1';
            case 'anthropic':
                return 'https://api.anthropic.com/v1';
            default:
                return AiConfig::config('ollama_base_url')
                    ?? self::str(AiConfig::fromEnv('OLLAMA_BASE_URL'))
                    ?? AiManifest::baseUrl();
        }
    }

    private static function defaultApiKey(string $provider): string
    {
        switch ($provider) {
            case 'openai':
                return AiConfig::apiKey();
            case 'anthropic':
                return AiConfig::fromEnv('ANTHROPIC_API_KEY');
            default:
                // Ollama ignores the bearer but some reverse proxies want one;
                // the literal keeps the header well-formed.
                return self::str(AiConfig::fromEnv('OLLAMA_API_KEY')) ?? 'ollama';
        }
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** The model for free-form chat (ChatAssistant). */
    public function chatModel(): string
    {
        return $this->chatModel;
    }

    /** The embedding model, or '' when nothing declares one. */
    public function embedModel(): string
    {
        return $this->embedModel;
    }

    /**
     * The vector width the embedding model must return, or 0 when nothing
     * declares one (the driver then accepts whatever it gets).
     */
    public function embedDimensions(): int
    {
        return $this->embedDimensions;
    }

    /**
     * Ollama's `keep_alive` for chat requests on this profile, or null when
     * none should be sent.
     *
     * @return int|string|null
     */
    public function keepAlive()
    {
        return $this->keepAlive;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function auth(): string
    {
        return $this->auth;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function retries(): int
    {
        return $this->retries;
    }

    public function throttle(): float
    {
        return $this->throttle;
    }

    /** @return array{input_per_m:float,output_per_m:float} */
    public function prices(): array
    {
        return $this->prices;
    }

    public function fallbackPolicy(): string
    {
        return $this->fallbackPolicy;
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    public function isFallback(): bool
    {
        return $this->isFallback;
    }

    /**
     * The cloud profile to use when local inference is down, or null.
     *
     * Non-null ONLY when the policy is cloud_if_configured AND the resolver
     * supplied the company's own key in `fallback.api_key`. The operator's
     * key (AiConfig::apiKey(), env) is never consulted here: a company that
     * chose local chose it for data residency, and shipping their mail to a
     * cloud on OUR account because a box rebooted is what ends a contract.
     */
    public function cloudFallback(): ?self
    {
        if ($this->isFallback || $this->fallbackPolicy !== 'cloud_if_configured') {
            return null;
        }
        if (self::str($this->fallbackSpec['api_key'] ?? null) === null) {
            return null;
        }
        $spec = $this->fallbackSpec;
        $provider = \strtolower((string) ($spec['provider'] ?? 'openai'));
        if (!\in_array($provider, ['openai', 'anthropic'], true)) {
            return null; // a "cloud" fallback onto another local box is not one
        }
        $spec['provider'] = $provider;
        $spec += [
            'timeout'        => $this->timeout,
            'retries'        => $this->retries,
            'throttle'       => $this->throttle,
            'prompt_version' => $this->promptVersion,
        ];

        return self::build($spec, true);
    }

    /** Alias of cloudFallback(). */
    public function withFallback(): ?self
    {
        return $this->cloudFallback();
    }

    /**
     * The $opts array for AiGateway::post().
     *
     * @return array<string,mixed>
     */
    public function gatewayOpts(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'api_key'  => $this->apiKey,
            'auth'     => $this->auth,
            'timeout'  => $this->timeout,
            'retries'  => $this->retries,
            'throttle' => $this->throttle,
        ];
    }
}
