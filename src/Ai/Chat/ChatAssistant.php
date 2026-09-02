<?php

namespace ApiGoat\Ai\Chat;

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
    public const MAX_TOKENS  = 600;
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
        $this->chat     = $chat ?? new OpenAiChat();
        $this->persona  = \trim($persona);
        $this->idTenant = $idTenant;
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
            '- Be concise: short paragraphs or a compact list, no preamble.',
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
        $pos = \mb_stripos($haystack, $needle);
        if ($pos === false) {
            return false;
        }
        // "#12" must not match inside "#123": the char after must not be a digit
        // when the needle ends with one.
        $after = \mb_substr($haystack, $pos + \mb_strlen($needle), 1);

        return !(\ctype_digit(\mb_substr($needle, -1)) && $after !== '' && \ctype_digit($after));
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
