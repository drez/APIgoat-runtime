<?php

declare(strict_types=1);

namespace ApiGoat\Tests\Ai;

use ApiGoat\Ai\AiGateway;
use ApiGoat\Ai\AiManifest;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/Ai/AiManifest.php';
require_once __DIR__ . '/../../src/Ai/AiConfig.php';
require_once __DIR__ . '/../../src/Ai/AiUsageLogger.php';
require_once __DIR__ . '/../../src/Ai/AiGateway.php';

final class AiGatewayOptsTest extends TestCase
{
    protected function setUp(): void
    {
        AiManifest::reset();
    }

    public function testDefaultUrlIsManifestBase(): void
    {
        self::assertSame(AiManifest::baseUrl() . '/chat/completions', AiGateway::urlFor('/chat/completions'));
        self::assertSame('https://api.openai.com/v1/chat/completions', AiGateway::urlFor('/chat/completions'));
    }

    public function testBaseUrlOverrideWithTrailingSlash(): void
    {
        self::assertSame(
            'http://192.168.1.144:11434/v1/chat/completions',
            AiGateway::urlFor('/chat/completions', ['base_url' => 'http://192.168.1.144:11434/v1/'])
        );
        self::assertSame(AiManifest::baseUrl() . '/x', AiGateway::urlFor('/x', ['base_url' => '']));
    }

    public function testDefaultHeadersUnchanged(): void
    {
        self::assertSame(
            ['Authorization: Bearer sk-1', 'Content-Type: application/json'],
            AiGateway::headersFor('sk-1')
        );
        self::assertSame(
            ['Authorization: Bearer sk-1', 'Content-Type: application/json'],
            AiGateway::headersFor('sk-1', ['auth' => 'bearer'])
        );
    }

    public function testXApiKeyAndNoneAndExtraHeaders(): void
    {
        self::assertSame(
            ['x-api-key: k', 'Content-Type: application/json', 'anthropic-version: 2023-06-01'],
            AiGateway::headersFor('k', ['auth' => 'x-api-key', 'headers' => ['anthropic-version: 2023-06-01']])
        );
        self::assertSame(
            ['Content-Type: application/json'],
            AiGateway::headersFor('ignored', ['auth' => 'none', 'headers' => ['', 42]])
        );
    }
}
