<?php

namespace ApiGoat\Ai\Chat;

/**
 * Conversation history in $_SESSION, one list per model (the `<Model>/chat`
 * endpoint the panel talks to), capped so a long session never grows the
 * session file without bound.
 *
 * Stored turns are {role, content} pairs the assistant replays verbatim,
 * plus the sources of each answer so the panel can re-render chips after a
 * reload. reset() is "New chat".
 */
final class ChatSessionStore
{
    public const SESSION_KEY = 'gc_ai_chat';
    /** Turns (user + assistant pairs) kept; older ones are dropped oldest-first. */
    public const MAX_TURNS = ChatAssistant::MAX_HISTORY_TURNS;

    private string $model;

    public function __construct(string $model)
    {
        if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $model)) {
            throw new \InvalidArgumentException('ChatSessionStore: invalid model name');
        }
        $this->model = $model;
    }

    /**
     * Prior turns for the assistant, oldest first.
     *
     * @return array<int,array{role:string,content:string}>
     */
    public function history(): array
    {
        $out = [];
        foreach ($this->turns() as $t) {
            $out[] = ['role' => 'user', 'content' => (string) $t['q']];
            $out[] = ['role' => 'assistant', 'content' => (string) $t['a']];
        }

        return $out;
    }

    /**
     * Turns with their sources, for re-rendering the panel.
     *
     * @return array<int,array{q:string,a:string,sources:array,at:int}>
     */
    public function turns(): array
    {
        $bag = $_SESSION[self::SESSION_KEY][$this->model] ?? [];

        return \is_array($bag) ? \array_values($bag) : [];
    }

    /**
     * @param array<int,array{id:string,label:string,href?:string}> $sources
     */
    public function append(string $question, string $answer, array $sources = []): void
    {
        $turns = $this->turns();
        $turns[] = ['q' => $question, 'a' => $answer, 'sources' => $sources, 'at' => \time()];
        if (\count($turns) > self::MAX_TURNS) {
            $turns = \array_slice($turns, -self::MAX_TURNS);
        }
        $this->write($turns);
    }

    public function reset(): void
    {
        $this->write([]);
    }

    /** @param array<int,array<string,mixed>> $turns */
    private function write(array $turns): void
    {
        if (!isset($_SESSION) || !\is_array($_SESSION)) {
            $_SESSION = [];
        }
        if (!isset($_SESSION[self::SESSION_KEY]) || !\is_array($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }
        if ($turns === []) {
            unset($_SESSION[self::SESSION_KEY][$this->model]);

            return;
        }
        $_SESSION[self::SESSION_KEY][$this->model] = $turns;
    }
}
