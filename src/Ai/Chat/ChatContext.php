<?php

namespace ApiGoat\Ai\Chat;

/**
 * Process-wide registry for the app's ContextProvider — the seam the emitted
 * `<Model>/chat` action resolves its grounding through.
 *
 * Same shape as AiProfile::setResolver(): the project registers once per
 * process (its web bootstrap and its CLI bootstrap), the emitted code only
 * ever calls provider(). Nothing registered means the project declared
 * `with_ai.chat` but never wired a provider; the endpoint answers 503 with
 * that exact message rather than inventing an answer from nothing.
 *
 * A callable may be registered instead of an instance so a provider that
 * needs a booted ORM is built lazily, on the first question.
 */
final class ChatContext
{
    /** @var ContextProvider|callable|null */
    private static $provider = null;

    /**
     * @param ContextProvider|callable|null $provider an instance, a factory
     *   returning one, or null to clear
     */
    public static function setProvider($provider): void
    {
        if ($provider !== null && !($provider instanceof ContextProvider) && !\is_callable($provider)) {
            throw new \InvalidArgumentException('ChatContext::setProvider expects a ContextProvider, a factory callable, or null');
        }
        self::$provider = $provider;
    }

    public static function hasProvider(): bool
    {
        return self::$provider !== null;
    }

    /** The registered provider (factories are resolved once), or null. */
    public static function provider(): ?ContextProvider
    {
        if (self::$provider === null) {
            return null;
        }
        if (!(self::$provider instanceof ContextProvider)) {
            $built = (self::$provider)();
            if (!($built instanceof ContextProvider)) {
                throw new \LogicException('ChatContext provider factory must return a ContextProvider');
            }
            self::$provider = $built;
        }

        return self::$provider;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$provider = null;
    }
}
