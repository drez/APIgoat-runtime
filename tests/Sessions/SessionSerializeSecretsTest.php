<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Sessions;

use ApiGoat\Sessions\AuthySession;
use PHPUnit\Framework\TestCase;

/**
 * Review 3 (2026-09-23): every session file and the shared-APCu bearer blob
 * carried JWT_SECRET (config['jwt']) and the user's bcrypt hash (passHash).
 */
final class SessionSerializeSecretsTest extends TestCase
{
    private function session(): AuthySession
    {
        $s = new AuthySession();
        $s->set('isConnected', 'YES');
        $s->set('id', 5);
        $s->set('passHash', '$2y$10$SECRETHASH');
        $s->config = ['jwt' => ['secret' => 'JWTSECRETVALUE'], 'locale' => ['locale' => 'fr_CA']];
        $s->setCsrf('csrf-token');
        $p = new \ReflectionProperty($s, 'Groups');
        $p->setAccessible(true);
        $p->setValue($s, [7, 9]);
        return $s;
    }

    public function testSecretsNeverReachTheSerializedForm(): void
    {
        $blob = serialize($this->session());
        self::assertStringNotContainsString('SECRETHASH', $blob);
        self::assertStringNotContainsString('JWTSECRETVALUE', $blob);
    }

    public function testEverythingElseRoundTrips(): void
    {
        $r = unserialize(serialize($this->session()), ['allowed_classes' => [AuthySession::class]]);
        self::assertInstanceOf(AuthySession::class, $r);
        self::assertSame('YES', $r->get('connected'));
        self::assertSame(5, $r->get('id'));
        self::assertSame('csrf-token', $r->getCsrf());
        self::assertSame('fr_CA', $r->config['locale']['locale']);
        self::assertArrayNotHasKey('jwt', $r->config);
        self::assertNull($r->get('passHash'));
        $p = new \ReflectionProperty($r, 'Groups');
        $p->setAccessible(true);
        self::assertSame([7, 9], $p->getValue($r));
    }

    public function testTheLiveObjectIsUntouched(): void
    {
        $s = $this->session();
        serialize($s);
        self::assertSame('$2y$10$SECRETHASH', $s->get('passHash'));
        self::assertSame('JWTSECRETVALUE', $s->config['jwt']['secret']);
    }

    public function testLegacyMangledPrivateNamesStillLoad(): void
    {
        // Shape written by the runtime before __serialize existed.
        $cls = AuthySession::class;
        $props = [
            'isConnected' => 'YES',
            "\0{$cls}\0Groups" => [7],
            'passHash' => 'LEGACYHASH',
        ];
        $body = '';
        foreach ($props as $k => $v) {
            $body .= serialize($k) . serialize($v);
        }
        $legacy = 'O:' . strlen($cls) . ':"' . $cls . '":' . count($props) . ':{' . $body . '}';
        $r = unserialize($legacy, ['allowed_classes' => [AuthySession::class]]);
        $p = new \ReflectionProperty($r, 'Groups');
        $p->setAccessible(true);
        self::assertSame([7], $p->getValue($r));
        self::assertSame('YES', $r->isConnected);
        self::assertNull($r->passHash);
    }
}
