<?php

namespace ApiGoat\Ai\Chat;

use ApiGoat\Ai\AiManifest;

/**
 * Process-wide registry for the app's ContextProvider — the seam the emitted
 * `<Model>/chat` action resolves its grounding through.
 *
 * Same shape as AiProfile::setResolver(): the project registers once per
 * process (its web bootstrap and its CLI bootstrap), the emitted code only
 * ever calls provider(). A project can skip the bootstrap line entirely by
 * DECLARING the class in HJSON instead — `chat: { provider: "App\\Domains\\…" }`
 * — which this class instantiates lazily on the first question and memoizes.
 * Nothing registered and nothing declared means the project declared
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

    /** Whether the manifest fallback has already been attempted this process. */
    private static bool $manifestTried = false;

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
        self::$manifestTried = false;
    }

    /** A provider was REGISTERED (the manifest declaration is not one until resolved). */
    public static function hasProvider(): bool
    {
        return self::$provider !== null;
    }

    /**
     * The registered provider (factories are resolved once), the class the
     * manifest declares, or null.
     *
     * A registration always wins. This method must NEVER throw on a bad
     * DECLARATION: a missing class, or one that is not a ContextProvider, is
     * error_log()ed and answered with null, which lands on the same 503 the
     * endpoint already had. The LogicException below stays for the
     * pre-existing case of a registered FACTORY returning the wrong type —
     * that is a programming error at registration time, not runtime input.
     */
    public static function provider(): ?ContextProvider
    {
        if (self::$provider === null) {
            return self::fromManifest();
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

    /**
     * Resolve `chat.provider` from the build-emitted manifest, once.
     *
     * Memoized on success (into self::$provider, so later calls take the
     * registered path) and on failure (self::$manifestTried, so a broken
     * declaration logs once per process rather than once per question).
     */
    private static function fromManifest(): ?ContextProvider
    {
        if (self::$manifestTried) {
            return null;
        }
        self::$manifestTried = true;

        $class = \trim((string) ((AiManifest::chat() ?? [])['provider'] ?? ''));
        if ($class === '') {
            return null;
        }
        if (!\class_exists($class)) {
            \error_log('[with_ai chat] declared provider class not found: ' . $class);

            return null;
        }
        try {
            $built = new $class();
        } catch (\Throwable $e) {
            \error_log('[with_ai chat] declared provider ' . $class . ' could not be constructed: ' . $e->getMessage());

            return null;
        }
        if (!($built instanceof ContextProvider)) {
            \error_log('[with_ai chat] declared provider ' . $class . ' does not implement ' . ContextProvider::class);

            return null;
        }
        self::$provider = $built;

        return $built;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$provider = null;
        self::$manifestTried = false;
    }
}
