<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiManifest;
use ApiGoat\Ai\AiProfile;

/**
 * A grounded question-answering turn: retrieve context from the app's
 * ContextProvider, assemble a bounded prompt, complete it in plain text on
 * the profile's chat model, and return the answer with the sources it cited.
 *
 * Generic by construction — the only app-specific inputs are the persona
 * sentence and the ContextProvider. The prompt rules are fixed: answer only
 * from the context, say so when it is not there, cite sources by label,
 * stay concise, answer in the question's language.
 *
 * Budget: history is capped to the last MAX_HISTORY_TURNS turns, and the
 * whole prompt must fit PROMPT_BUDGET_CHARS — oldest turns are dropped
 * first, then the context block is head-truncated (it is built most
 * important first) down to CONTEXT_MAX_CHARS or less.
 */
final class ChatAssistant
{
    public const MAX_HISTORY_TURNS  = 8;
    public const CONTEXT_MAX_CHARS  = 6000;
    public const PROMPT_BUDGET_CHARS = 14000;
    /**
     * A ceiling, not a target: the brevity rules in systemPrompt() are what keep
     * answers short. 600 was room for prose nobody asked for — on a local model
     * every output token is ~57 ms, so it was the larger half of a 10 s turn.
     * 400 still clears a compliant answer (8 lines + the Sources line ≈ 220
     * tokens) with margin, so the cap never truncates the citation line, which
     * citedSources() parses.
     */
    public const MAX_TOKENS  = 400;
    public const TEMPERATURE = 0.2;

    private AiProfile $profile;
    private ContextProvider $ctx;
    private ChatDriver $chat;
    private string $persona;
    private ?int $idTenant;

    public function __construct(AiProfile $profile, ContextProvider $ctx, ?ChatDriver $chat = null, string $persona = '', ?int $idTenant = null)
    {
        $this->profile  = $profile;
        $this->ctx      = $ctx;
        $this->chat     = $chat ?? self::driverFor($profile);
        $this->persona  = \trim($persona);
        $this->idTenant = $idTenant;
    }

    /**
     * The driver that can actually talk to this provider.
     *
     * Ollama's /v1 shim cannot disable reasoning — measured on qwen3.5:9b,
     * same prompt and host: 222,805 ms and 3,847 discarded tokens through
     * /v1 with think:false, against 1,003 ms and 14 through /api/chat.
     * OllamaChat speaks the native endpoint and defaults think:false.
     *
     * The escape hatch is the manifest's optional `chat.driver`:
     *   auto (default) — native for ollama, /chat/completions otherwise
     *   native         — always OllamaChat
     *   openai         — always OpenAiChat (the pre-existing behaviour;
     *                    this is what to set when `provider: "ollama"`
     *                    actually points at llama.cpp / LM Studio / vLLM,
     *                    which speak /v1 but have no /api/chat).
     */
    public static function driverFor(AiProfile $profile): ChatDriver
    {
        $declared = (string) ((AiManifest::chat() ?? [])['driver'] ?? 'auto');
        if ($declared === 'openai') {
            return new OpenAiChat();
        }
        if ($declared === 'native') {
            return new OllamaChat();
        }

        return $profile->provider() === 'ollama' ? new OllamaChat() : new OpenAiChat();
    }

    /**
     * @param array<int,array{role:string,content:string}> $history prior turns, oldest first
     * @throws ChatFailed when the model does not answer
     */
    public function ask(string $question, array $history): ChatAnswer
    {
        $question = \trim($question);
        $bundle   = $this->ctx->retrieve($question, $history, $this->idTenant);
        $messages = self::assemble($this->persona, $bundle->text, $history, $question);

        $model = $this->profile->chatModel();
        if ($model === '') {
            throw new ChatFailed('no chat model configured for provider ' . $this->profile->provider(), 0);
        }

        $r = $this->chat->complete($this->profile, $messages, [
            'model'       => $model,
            'temperature' => self::TEMPERATURE,
            'max_tokens'  => self::MAX_TOKENS,
        ]);
        if (!$r->ok()) {
            $why = $r->transportError() !== '' ? $r->transportError() : 'HTTP ' . $r->status();
            throw new ChatFailed('the model did not answer (' . $why . ')', $r->status());
        }
        $text = \trim((string) $r->text());
        if ($text === '') {
            throw new ChatFailed('the model returned an empty answer', $r->status());
        }

        return new ChatAnswer($text, self::citedSources($text, $bundle->sources), $r->usage(), $r->latencyMs(), $model);
    }

    /**
     * The message list for the driver: system (rules + persona + context),
     * the trimmed history, then the question. Pure — unit-tested directly.
     *
     * @param array<int,array{role:string,content:string}> $history
     * @return array<int,array{role:string,content:string}>
     */
    public static function assemble(string $persona, string $context, array $history, string $question): array
    {
        $history = self::cleanHistory($history);
        $context = self::headTruncate($context, self::CONTEXT_MAX_CHARS);

        // Drop the oldest turns until the prompt fits, then cut the context.
        $fixed = \strlen(self::systemPrompt($persona, '')) + \strlen($question);
        while ($history !== [] && $fixed + \strlen($context) + self::historyLength($history) > self::PROMPT_BUDGET_CHARS) {
            $history = \array_slice($history, 2);
        }
        $room = self::PROMPT_BUDGET_CHARS - $fixed - self::historyLength($history);
        if (\strlen($context) > $room) {
            $context = self::headTruncate($context, \max(0, $room));
        }

        $messages = [['role' => 'system', 'content' => self::systemPrompt($persona, $context)]];
        foreach ($history as $h) {
            $messages[] = $h;
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        return $messages;
    }

    public static function systemPrompt(string $persona, string $context): string
    {
        $persona = \trim($persona);
        $lines = [
            $persona !== '' ? $persona : 'You are an assistant that answers questions about this application\'s data.',
            'Rules:',
            '- Answer ONLY from the CONTEXT below. It is the complete set of facts available to you.',
            '- If the context does not contain the answer, say so plainly; never guess or invent records.',
            '- When you use a fact, cite its source by its label exactly as written in the context (for example "#123").',
            '- End every answer with one line "Sources: #id, #id" listing the labels of every email you used (omit the line only when you used none).',
            // "Be concise" alone does not work on a 9B model: it still writes a
            // sentence of prose per record. Measured on gm-triage:v3, the same
            // question produced 144 output tokens once and 500+ the next run.
            // Concrete limits are what hold — one line per record, a cap on the
            // number of lines, and an explicit ban on restating the question.
            // Length limits alone swing the model between two failure modes: a
            // vague "be concise" gave a paragraph of prose per record (500+
            // output tokens), while "one SHORT line per record" collapsed to a
            // bare list of ids with no information in it at all. What holds is
            // an explicit FORMAT with a worked example.
            '- One line per record, in exactly this shape:',
            '    #id — Who: what they want (max 10 words after the colon)',
            '  For example: "#3214 — Martin Kirouac: sent rates, wants a go-ahead".',
            '  Never a bare list of ids: every line must say who and what.',
            '- No preamble, no restating the question, no closing summary.',
            // The item cap is the single biggest lever on turn latency: every
            // output token costs ~57 ms on a local model, so each extra line is
            // roughly a second. 5 covers "what should I look at" — the caller
            // has a full, sorted list one click away and does not need the model
            // to recite it. Measured on gm-triage:v3: 8 lines = 242 tokens/14.0 s,
            // 5 lines = ~150 tokens/~9 s.
            '- List at most 5 records, most important first. If more match, end with',
            '  one line: "(+N more)".',
            '- Only explain further if the question actually asks why or how.',
            '- Answer in the same language as the question.',
            '',
            'CONTEXT:',
            $context !== '' ? $context : '(no context was retrieved for this question)',
        ];

        return \implode("\n", $lines);
    }

    /**
     * Keep the sources whose label (or id) the answer mentions; when it
     * mentions none, keep nothing rather than pretending every record was
     * used.
     *
     * @param array<int,array{id:string,label:string,href?:string}> $sources
     * @return array<int,array{id:string,label:string,href?:string}>
     */
    public static function citedSources(string $answer, array $sources): array
    {
        $out = [];
        foreach ($sources as $s) {
            $label = (string) ($s['label'] ?? '');
            $id    = (string) ($s['id'] ?? '');
            if (($label !== '' && self::mentions($answer, $label)) || ($id !== '' && $id !== $label && self::mentions($answer, $id))) {
                $out[] = $s;
            }
        }

        return $out;
    }

    private static function mentions(string $haystack, string $needle): bool
    {
        // "#12" must not match inside "#123" (nor "12" inside "312"): a digit
        // at either end of the needle needs a non-digit neighbour. ANY
        // occurrence counts — testing only the first one dropped "#12"
        // whenever "#123" appeared earlier in the answer.
        $pattern = (\ctype_digit(\mb_substr($needle, 0, 1)) ? '(?<!\d)' : '')
            . \preg_quote($needle, '/')
            . (\ctype_digit(\mb_substr($needle, -1)) ? '(?!\d)' : '');
        $found = @\preg_match('/' . $pattern . '/iu', $haystack);
        if ($found === false) {
            $found = \preg_match('/' . $pattern . '/i', $haystack); // not valid UTF-8
        }

        return $found === 1;
    }

    /**
     * Only well-formed user/assistant pairs, last MAX_HISTORY_TURNS turns.
     *
     * @param array<int,mixed> $history
     * @return array<int,array{role:string,content:string}>
     */
    private static function cleanHistory(array $history): array
    {
        $clean = [];
        foreach ($history as $h) {
            if (!\is_array($h) || !isset($h['role'], $h['content'])) {
                continue;
            }
            if (!\in_array($h['role'], ['user', 'assistant'], true)) {
                continue;
            }
            $clean[] = ['role' => (string) $h['role'], 'content' => (string) $h['content']];
        }
        // Start on a user message so the replay always reads as pairs.
        while ($clean !== [] && $clean[0]['role'] !== 'user') {
            \array_shift($clean);
        }
        $max = self::MAX_HISTORY_TURNS * 2;
        if (\count($clean) > $max) {
            $clean = \array_slice($clean, -$max);
            while ($clean !== [] && $clean[0]['role'] !== 'user') {
                \array_shift($clean);
            }
        }

        return $clean;
    }

    /** @param array<int,array{role:string,content:string}> $history */
    private static function historyLength(array $history): int
    {
        $n = 0;
        foreach ($history as $h) {
            $n += \strlen($h['content']) + 16;
        }

        return $n;
    }

    /** Keep the first $max bytes (on a line boundary when possible) and mark the cut. */
    public static function headTruncate(string $text, int $max): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }
        if ($max <= 0) {
            return '';
        }
        $cut = \substr($text, 0, $max);
        $nl  = \strrpos($cut, "\n");
        if ($nl !== false && $nl > $max * 0.6) {
            $cut = \substr($cut, 0, $nl);
        }
        // Never end on a split multi-byte sequence.
        while ($cut !== '' && !\mb_check_encoding($cut, 'UTF-8')) {
            $cut = \substr($cut, 0, -1);
        }

        return \rtrim($cut) . "\n[… context truncated]";
    }
}
