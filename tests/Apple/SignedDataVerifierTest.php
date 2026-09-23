<?php

namespace ApiGoat\Tests\Apple;

use ApiGoat\Apple\SignedDataVerifier;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Apple/SignedDataVerifier.php';
require_once __DIR__ . '/AppleTestChain.php';

final class SignedDataVerifierTest extends TestCase
{
    private function payload(): array
    {
        return ['transactionId' => '2000000123', 'bundleId' => 'com.example.app', 'signedDate' => \time() * 1000];
    }

    public function testAcceptsAValidChainAndSignature(): void
    {
        $c = new AppleTestChain();
        $out = (new SignedDataVerifier([$c->rootSha256]))->verify($c->jws($this->payload()));
        $this->assertSame('2000000123', $out['transactionId']);
    }

    public function testRejectsAnUnpinnedRootEvenIfTheChainIsSelfConsistent(): void
    {
        $c = new AppleTestChain();
        $this->expectExceptionMessage('Untrusted root');
        (new SignedDataVerifier())->verify($c->jws($this->payload())); // default pin = Apple G3
    }

    public function testRejectsATamperedPayload(): void
    {
        $c = new AppleTestChain();
        [$h, , $s] = \explode('.', $c->jws($this->payload()));
        $forged = AppleTestChain::b64(\json_encode(['transactionId' => '999'] + $this->payload()));
        $this->expectExceptionMessage('signature does not verify');
        (new SignedDataVerifier([$c->rootSha256]))->verify("$h.$forged.$s");
    }

    public function testRejectsALeafSignedByAnotherChain(): void
    {
        $a = new AppleTestChain();
        $b = new AppleTestChain();
        // b's leaf + intermediate under a's (trusted) root: chain link fails
        $x5c = [$b->x5c[0], $b->x5c[1], $a->x5c[2]];
        $this->expectExceptionMessage('chain does not verify');
        (new SignedDataVerifier([$a->rootSha256]))->verify($b->jws($this->payload(), $x5c));
    }

    public function testRequiresAppleMarkerOids(): void
    {
        $c = new AppleTestChain(leafOid: false);
        $this->expectExceptionMessage('not an App Store signing certificate');
        (new SignedDataVerifier([$c->rootSha256]))->verify($c->jws($this->payload()));
    }

    public function testRejectsASignatureOutsideTheCertificateValidity(): void
    {
        $c = new AppleTestChain();
        $p = ['signedDate' => (\time() + 400 * 86400) * 1000] + $this->payload();
        $this->expectExceptionMessage('not valid at signing time');
        (new SignedDataVerifier([$c->rootSha256]))->verify($c->jws($p));
    }

    public function testRejectsNonEs256(): void
    {
        $h = AppleTestChain::b64(\json_encode(['alg' => 'none']));
        $this->expectExceptionMessage('algorithm');
        (new SignedDataVerifier())->verify("$h." . AppleTestChain::b64('{}') . '.');
    }
}
