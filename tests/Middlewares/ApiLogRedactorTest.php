<?php

namespace ApiGoat\Tests\Middlewares;

use ApiGoat\Middlewares\ApiLogRedactor;
use PHPUnit\Framework\TestCase;

/**
 * Review 2026-09-23: RbacMiddleware wrote the raw query string and body to
 * api_log.raw_parameters in plain text — passwords, tokens, OAuth codes.
 */
final class ApiLogRedactorTest extends TestCase
{
    public function testFormBodyCredentialsAreRedacted(): void
    {
        $out = ApiLogRedactor::redactBody('u=fred&p=Secret1!&csrf=abc&Name=Acme', 'application/x-www-form-urlencoded');
        self::assertStringNotContainsString('Secret1', $out);
        self::assertStringNotContainsString('abc', $out);
        self::assertStringContainsString('u=fred', $out);
        self::assertStringContainsString('Name=Acme', $out);
    }

    public function testJsonBodyIsRedactedRecursively(): void
    {
        $body = json_encode(['params' => ['arguments' => ['password_new' => 'hunter2', 'Name' => 'Acme',
            'nested' => [['access_token' => 'tok1', 'client_secret' => 's3', 'code_verifier' => 'v']]]]]);
        $out = ApiLogRedactor::redactBody($body, 'application/json');
        foreach (['hunter2', 'tok1', 's3', '"v"'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
        self::assertStringContainsString('Acme', $out);
        self::assertStringContainsString(ApiLogRedactor::MASK, $out);
    }

    public function testQueryStringIsRedacted(): void
    {
        $out = ApiLogRedactor::redactQueryString('code=AUTHCODE&state=xyz&data%5Bpasswd%5D=PWVAL&refresh_token=RTVAL&api_key=AKVAL');
        foreach (['AUTHCODE', 'PWVAL', 'RTVAL', 'AKVAL'] as $secret) {
            self::assertStringNotContainsString($secret, urldecode($out));
        }
        self::assertStringContainsString('state=xyz', $out);
    }

    public function testSensitiveKeyNames(): void
    {
        foreach (['p', 'pass', 'passwd', 'PasswdHash', 'password', 'Password_confirm', 'token', 'access_token',
                  'refresh_token', 'id_token', 'code', 'code_verifier', 'credential', 'client_secret', 'secret',
                  'key', 'api_key', 'csrf', 'iarc_csrf'] as $k) {
            self::assertTrue(ApiLogRedactor::isSensitiveKey($k), $k);
        }
        foreach (['u', 'Name', 'query', 'select', 'IdClient', 'state', 'page'] as $k) {
            self::assertFalse(ApiLogRedactor::isSensitiveKey($k), $k);
        }
    }

    public function testCredentialRoutesNeverLogTheBody(): void
    {
        foreach (['/test/.admin/Authy/auth', '/test/.admin/api/v1/Authy/auth', '/test/.admin/oauth/token',
                  '/test/.admin/oauth/register', '/test/.admin/api/v1/oauth/google', '/test/.admin/Authy/resetConfirm'] as $path) {
            self::assertTrue(ApiLogRedactor::isCredentialRoute($path, '/test/.admin/'), $path);
        }
        self::assertFalse(ApiLogRedactor::isCredentialRoute('/test/.admin/api/v1/Client', '/test/.admin/'));
        self::assertFalse(ApiLogRedactor::isCredentialRoute('/test/.admin/Client/list/oauth', '/test/.admin/'));
    }

    public function testRawParametersCombinesQueryAndBody(): void
    {
        if (!defined('_SUB_DIR_URL')) {
            define('_SUB_DIR_URL', '/test/.admin/');
        }
        $out = ApiLogRedactor::rawParameters(_SUB_DIR_URL . 'api/v1/Client', 'token=t1&page=2', null,
            '{"Name":"Acme","secret":"zz"}', 'application/json');
        self::assertStringNotContainsString('t1', $out);
        self::assertStringNotContainsString('zz', urldecode($out));
        self::assertStringContainsString('page=2', $out);
        self::assertStringContainsString('&body=', $out);

        $login = ApiLogRedactor::rawParameters(_SUB_DIR_URL . 'api/v1/Authy/auth', '', null, 'u=a&p=b', '');
        self::assertStringNotContainsString('p=b', urldecode($login));

        $q = ApiLogRedactor::rawParameters(_SUB_DIR_URL . 'api/v1/Client', '', ['filter' => ['Name' => 'x'], 'token' => 'y'], '', '');
        self::assertStringNotContainsString('"y"', $q);
        self::assertStringContainsString('Name', $q);
    }

    public function testUnparsableBodyIsNotLogged(): void
    {
        $out = ApiLogRedactor::redactBody("--b\r\nContent-Disposition: form-data; name=\"p\"\r\n\r\nsecret\r\n", 'multipart/form-data; boundary=b');
        self::assertStringNotContainsString('secret', $out);
    }
}
