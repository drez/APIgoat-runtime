<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\Chat\ChatContext;
use ApiGoat\Ai\Chat\ContextBundle;
use ApiGoat\Ai\Chat\ContextProvider;
use ApiGoat\Tests\Ai\Support\ManifestFixture;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/Chat/ContextProvider.php';
require_once __DIR__ . '/../../src/Ai/Chat/ContextBundle.php';
require_once __DIR__ . '/../../src/Ai/Chat/ChatContext.php';
require_once __DIR__ . '/support/ManifestFixture.php';

/** A declared provider, instantiable with no arguments — the manifest case. */
class DeclaredProvider implements ContextProvider
{
    public static int $built = 0;

    public function __construct()
    {
        self::$built++;
    }

    public function retrieve(string $question, array $history, ?int $idTenant): ContextBundle
    {
        return new ContextBundle('declared', []);
    }
}

/** Declared but not a ContextProvider — a misconfiguration, not a crash. */
class NotAProvider
{
}

/** Declared, constructible in principle, but throws while booting. */
class ExplodingProvider implements ContextProvider
{
    public function __construct()
    {
        throw new \RuntimeException('no database');
    }

    public function retrieve(string $question, array $history, ?int $idTenant): ContextBundle
    {
        return ContextBundle::empty();
    }
}

final class RegisteredProvider implements ContextProvider
{
    public function retrieve(string $question, array $history, ?int $idTenant): ContextBundle
    {
        return new ContextBundle('registered', []);
    }
}

final class ChatContextTest extends TestCase
{
    protected function setUp(): void
    {
        ChatContext::reset();
        ManifestFixture::clear();
        DeclaredProvider::$built = 0;
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /** No registration and no declaration → null, i.e. the existing 503. */
    public function testNoProviderAndNoManifestKeyReturnsNull(): void
    {
        self::assertNull(ChatContext::provider());
        self::assertFalse(ChatContext::hasProvider());

        ManifestFixture::write(['chat' => ['table' => 'mail_message', 'model' => 'MailMessage']]);
        ChatContext::reset();
        self::assertNull(ChatContext::provider(), 'a chat block without a provider key changes nothing');
    }

    /** A registration always wins over the declaration. */
    public function testSetProviderWinsOverTheManifest(): void
    {
        ManifestFixture::write(self::manifestWith(DeclaredProvider::class));
        $registered = new RegisteredProvider();
        ChatContext::setProvider($registered);

        self::assertSame($registered, ChatContext::provider());
        self::assertSame(0, DeclaredProvider::$built, 'the declared class is never even built');
    }

    /** A registered FACTORY still wins, and is still resolved exactly once. */
    public function testRegisteredFactoryStillWinsAndResolvesOnce(): void
    {
        ManifestFixture::write(self::manifestWith(DeclaredProvider::class));
        $calls = 0;
        ChatContext::setProvider(function () use (&$calls): ContextProvider {
            $calls++;

            return new RegisteredProvider();
        });

        $a = ChatContext::provider();
        $b = ChatContext::provider();
        self::assertInstanceOf(RegisteredProvider::class, $a);
        self::assertSame($a, $b);
        self::assertSame(1, $calls);
    }

    /** Pinned: a registered factory returning the wrong type is still a LogicException. */
    public function testRegisteredFactoryReturningTheWrongTypeStillThrows(): void
    {
        ChatContext::setProvider(fn () => new NotAProvider());
        $this->expectException(\LogicException::class);
        ChatContext::provider();
    }

    public function testManifestProviderIsResolvedLazilyAndMemoized(): void
    {
        ManifestFixture::write(self::manifestWith(DeclaredProvider::class));

        self::assertSame(0, DeclaredProvider::$built, 'nothing is built until the first question');
        $a = ChatContext::provider();
        $b = ChatContext::provider();

        self::assertInstanceOf(DeclaredProvider::class, $a);
        self::assertSame($a, $b);
        self::assertSame(1, DeclaredProvider::$built, 'built once');
        self::assertSame('declared', $a->retrieve('q', [], null)->text);
    }

    /**
     * The whole point of resolving defensively: today provider() can only
     * return null, and a declared-but-broken class must not become a brand
     * new exception escaping into a request.
     */
    public function testAMissingClassLogsAndReturnsNullRatherThanThrowing(): void
    {
        ManifestFixture::write(self::manifestWith('App\\Domains\\Search\\NoSuchProvider'));

        $log = self::captureErrorLog(static fn () => ChatContext::provider());

        self::assertNull($log['return']);
        self::assertStringContainsString('NoSuchProvider', $log['text']);
    }

    public function testAClassThatIsNotAContextProviderLogsAndReturnsNull(): void
    {
        ManifestFixture::write(self::manifestWith(NotAProvider::class));

        $log = self::captureErrorLog(static fn () => ChatContext::provider());

        self::assertNull($log['return']);
        self::assertStringContainsString('NotAProvider', $log['text']);
        self::assertStringContainsString('ContextProvider', $log['text']);
    }

    public function testAConstructorThatThrowsIsSwallowedAndLogged(): void
    {
        ManifestFixture::write(self::manifestWith(ExplodingProvider::class));

        $log = self::captureErrorLog(static fn () => ChatContext::provider());

        self::assertNull($log['return']);
        self::assertStringContainsString('no database', $log['text']);
    }

    /** A broken declaration logs ONCE per process, not once per question. */
    public function testABrokenDeclarationIsOnlyLoggedOnce(): void
    {
        ManifestFixture::write(self::manifestWith('App\\NoSuchProviderEither'));

        $log = self::captureErrorLog(static function (): void {
            ChatContext::provider();
            ChatContext::provider();
            ChatContext::provider();
        });

        self::assertSame(1, \substr_count($log['text'], 'NoSuchProviderEither'));
    }

    /** setProvider(null) re-opens the manifest path (reset semantics). */
    public function testClearingTheRegistrationFallsBackToTheManifestAgain(): void
    {
        ManifestFixture::write(self::manifestWith(DeclaredProvider::class));
        ChatContext::setProvider(new RegisteredProvider());
        self::assertInstanceOf(RegisteredProvider::class, ChatContext::provider());

        ChatContext::setProvider(null);
        self::assertInstanceOf(DeclaredProvider::class, ChatContext::provider());
    }

    /** @return array{return:mixed,text:string} */
    private static function captureErrorLog(callable $fn): array
    {
        $file = \tempnam(\sys_get_temp_dir(), 'gc-errlog');
        $prevLog = \ini_get('error_log');
        $prevDisplay = \ini_get('log_errors');
        \ini_set('error_log', $file);
        \ini_set('log_errors', '1');
        try {
            $return = $fn();
        } finally {
            \ini_set('error_log', (string) $prevLog);
            \ini_set('log_errors', (string) $prevDisplay);
        }
        $text = \is_file($file) ? (string) \file_get_contents($file) : '';
        @\unlink($file);

        return ['return' => $return, 'text' => $text];
    }

    /** @return array<string,mixed> */
    private static function manifestWith(string $providerClass): array
    {
        return [
            'base_url' => 'http://box:11434/v1',
            'chat' => [
                'table'    => 'mail_message',
                'model'    => 'MailMessage',
                'label'    => 'Ask about my email',
                'provider' => $providerClass,
            ],
        ];
    }
}
