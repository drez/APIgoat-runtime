<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiConfig;
use ApiGoat\Ai\AiGateway;

/**
 * A normalised chat answer: the text, the token counts whichever name the
 * provider gave them, and a JSON decode that knows the difference between
 * "the model did not answer in JSON" and "the model answered `{}`".
 */
final class ChatResult
{
    private int $status;
    private ?string $text;
    /** @var array{input_tokens:int,output_tokens:int} */
    private array $usage;
    private int $latencyMs;
    /** @var mixed */
    private $raw;
    private string $transportError;

    private bool $decoded = false;
    /** @var mixed */
    private $json = null;
    private bool $jsonValid = false;

    /**
     * @param array<string,mixed> $usage prompt_tokens/completion_tokens or input_tokens/output_tokens
     * @param mixed $raw the decoded response body as the provider sent it
     */
    public function __construct(
        int $status,
        ?string $text,
        array $usage = [],
        int $latencyMs = 0,
        $raw = null,
        string $transportError = ''
    ) {
        $this->status = $status;
        $this->text = $text;
        $this->usage = [
            'input_tokens'  => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
        ];
        $this->latencyMs = $latencyMs;
        $this->raw = $raw;
        $this->transportError = $transportError;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** 2xx and something came back. */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->transportError === '';
    }

    public function text(): ?string
    {
        return $this->text;
    }

    /** @return array{input_tokens:int,output_tokens:int} */
    public function usage(): array
    {
        return $this->usage;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    /** @return mixed */
    public function raw()
    {
        return $this->raw;
    }

    public function transportError(): string
    {
        return $this->transportError;
    }

    /**
     * The text decoded as JSON (```json fences tolerated), or null.
     *
     * A null here means either "not JSON" or the literal JSON `null`;
     * jsonValid() tells them apart. A model answering `{}` yields [] with
     * jsonValid() === true — it IS JSON, just an empty object.
     *
     * @return mixed
     */
    public function decodeJson()
    {
        if (!$this->decoded) {
            $this->decoded = true;
            $t = $this->text === null ? '' : \trim($this->text);
            if ($t !== '') {
                $this->json = AiGateway::decodeJson($t);
                $this->jsonValid = \json_last_error() === JSON_ERROR_NONE;
            }
        }

        return $this->json;
    }

    public function jsonValid(): bool
    {
        $this->decodeJson();

        return $this->jsonValid;
    }

    /**
     * USD for this answer at the given per-million prices.
     *
     * @param array{input_per_m:float,output_per_m:float} $prices
     */
    public function costUsd(array $prices): float
    {
        return AiConfig::priceOf(
            $this->usage['input_tokens'],
            $this->usage['output_tokens'],
            (float) ($prices['input_per_m'] ?? 0.0),
            (float) ($prices['output_per_m'] ?? 0.0)
        );
    }
}
