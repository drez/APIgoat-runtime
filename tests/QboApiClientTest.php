<?php
// tests/QboApiClientTest.php — Run: php tests/QboApiClientTest.php
require __DIR__ . '/../src/Sync/Exceptions/AuthFailed.php';
require __DIR__ . '/../src/Sync/Exceptions/RateLimited.php';
require __DIR__ . '/../src/Sync/Exceptions/TransientError.php';
require __DIR__ . '/../src/Sync/Exceptions/ValidationRejected.php';
require __DIR__ . '/../src/Sync/QuickBooks/QboApiClient.php';

use ApiGoat\Sync\QuickBooks\QboApiClient;

$fails = 0;
function check(string $label, $got, $want): void {
    global $fails;
    if ($got !== $want) { $fails++; echo "FAIL $label: got " . var_export($got, true) . " want " . var_export($want, true) . "\n"; }
    else { echo "ok   $label\n"; }
}

$client = new QboApiClient('cid', 'csecret', 'sandbox');
check('sandbox base', $client->apiBase(), 'https://sandbox-quickbooks.api.intuit.com');
check('prod base', (new QboApiClient('c', 's', 'production'))->apiBase(), 'https://quickbooks.api.intuit.com');

$url = $client->authorizeUrl('https://app.example/.admin/Sync/callback', 'st4te');
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
check('authorize host', str_starts_with($url, 'https://appcenter.intuit.com/connect/oauth2?'), true);
check('authorize params', [$q['client_id'], $q['response_type'], $q['scope'], $q['state']],
      ['cid', 'code', 'com.intuit.quickbooks.accounting', 'st4te']);

// Transport capture: token exchange posts Basic auth + form body
$captured = [];
$client->transport = function (string $m, string $u, array $h, ?string $b) use (&$captured): array {
    $captured = [$m, $u, $h, $b];
    return ['status' => 200, 'body' => json_encode(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600])];
};
$tok = $client->exchangeCode('thecode', 'https://cb');
check('token url', $captured[1], 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
check('basic auth header', in_array('Authorization: Basic ' . base64_encode('cid:csecret'), $captured[2], true), true);
parse_str((string) $captured[3], $form);
check('grant form', [$form['grant_type'], $form['code']], ['authorization_code', 'thecode']);
check('token parsed', $tok['access_token'], 'AT');

// api(): URL shape + bearer + error taxonomy
$client->transport = function ($m, $u) use (&$captured) { $captured = [$m, $u]; return ['status' => 200, 'body' => '{"Invoice":{"Id":"9"}}']; };
$res = $client->api('123', 'POST', 'invoice', ['a' => 1], 'AT');
check('api url', $captured[1], 'https://sandbox-quickbooks.api.intuit.com/v3/company/123/invoice?minorversion=75');
check('api decoded', $res['Invoice']['Id'], '9');

$client->transport = fn () => ['status' => 401, 'body' => ''];
$threw = null; try { $client->api('1', 'GET', 'x', null, 'AT'); } catch (\Throwable $e) { $threw = get_class($e); }
check('401 => AuthFailed', $threw, 'ApiGoat\Sync\Exceptions\AuthFailed');
$client->transport = fn () => ['status' => 429, 'body' => ''];
$threw = null; try { $client->api('1', 'GET', 'x', null, 'AT'); } catch (\Throwable $e) { $threw = get_class($e); }
check('429 => RateLimited', $threw, 'ApiGoat\Sync\Exceptions\RateLimited');
$client->transport = fn () => ['status' => 502, 'body' => ''];
$threw = null; try { $client->api('1', 'GET', 'x', null, 'AT'); } catch (\Throwable $e) { $threw = get_class($e); }
check('5xx => TransientError', $threw, 'ApiGoat\Sync\Exceptions\TransientError');
$client->transport = fn () => ['status' => 400, 'body' => '{"Fault":{"Error":[{"Message":"Bad","Detail":"Duplicate DocNumber"}]}}'];
$threw = null; $msg = '';
try { $client->api('1', 'POST', 'invoice', [], 'AT'); } catch (\Throwable $e) { $threw = get_class($e); $msg = $e->getMessage(); }
check('400 => ValidationRejected', $threw, 'ApiGoat\Sync\Exceptions\ValidationRejected');
check('400 carries QBO detail', str_contains($msg, 'Duplicate DocNumber'), true);

// query(): urlencoded + unwraps QueryResponse
$client->transport = function ($m, $u) use (&$captured) { $captured = [$m, $u]; return ['status' => 200, 'body' => '{"QueryResponse":{"Customer":[{"Id":"5"}]}}']; };
$qr = $client->query('123', "select Id from Customer where DisplayName = 'Acme'", 'AT');
check('query unwrapped', $qr['Customer'][0]['Id'], '5');
check('query urlencoded', str_contains($captured[1], 'query?query=select%20Id%20from%20Customer'), true);

// fromEnv(): missing credentials → AuthFailed
putenv('QB_CLIENT_ID');
putenv('QB_CLIENT_SECRET');
putenv('QB_ENV');
unset($_ENV['QB_CLIENT_ID'], $_ENV['QB_CLIENT_SECRET'], $_ENV['QB_ENV']);
$threw = null;
try { QboApiClient::fromEnv(); } catch (\Throwable $e) { $threw = get_class($e); }
check('fromEnv missing => AuthFailed', $threw, 'ApiGoat\Sync\Exceptions\AuthFailed');

// fromEnv(): production env via env vars
$_ENV['QB_CLIENT_ID'] = 'envid';
$_ENV['QB_CLIENT_SECRET'] = 'envsec';
$_ENV['QB_ENV'] = 'production';
$env_client = QboApiClient::fromEnv();
check('fromEnv prod apiBase', $env_client->apiBase(), 'https://quickbooks.api.intuit.com');
$auth_url = $env_client->authorizeUrl('https://cb', 'st');
check('fromEnv authorizeUrl has client_id', str_contains($auth_url, 'client_id=envid'), true);
// Clean up env vars
unset($_ENV['QB_CLIENT_ID'], $_ENV['QB_CLIENT_SECRET'], $_ENV['QB_ENV']);
putenv('QB_CLIENT_ID');
putenv('QB_CLIENT_SECRET');
putenv('QB_ENV');

// fromEnv(): default to sandbox when QB_ENV unset
$_ENV['QB_CLIENT_ID'] = 'envid2';
$_ENV['QB_CLIENT_SECRET'] = 'envsec2';
// QB_ENV intentionally not set
$sandbox_client = QboApiClient::fromEnv();
check('fromEnv default sandbox', $sandbox_client->apiBase(), 'https://sandbox-quickbooks.api.intuit.com');
// Clean up
unset($_ENV['QB_CLIENT_ID'], $_ENV['QB_CLIENT_SECRET']);
putenv('QB_CLIENT_ID');
putenv('QB_CLIENT_SECRET');

// refreshToken(): grant_type and form body via transport
$refresh_client = new QboApiClient('rid', 'rsec', 'sandbox');
$refresh_captured = [];
$refresh_client->transport = function (string $m, string $u, array $h, ?string $b) use (&$refresh_captured): array {
    $refresh_captured = [$m, $u, $h, $b];
    return ['status' => 200, 'body' => json_encode(['access_token' => 'NEWAT', 'refresh_token' => 'NEWRT', 'expires_in' => 3600])];
};
$new_tok = $refresh_client->refreshToken('RTOK');
check('refreshToken returns access_token', $new_tok['access_token'], 'NEWAT');
parse_str((string) $refresh_captured[3], $refresh_form);
check('refreshToken grant_type', $refresh_form['grant_type'], 'refresh_token');
check('refreshToken refresh_token param', $refresh_form['refresh_token'], 'RTOK');
check('refreshToken Basic auth header', in_array('Authorization: Basic ' . base64_encode('rid:rsec'), $refresh_captured[2], true), true);

exit($fails ? 1 : 0);
