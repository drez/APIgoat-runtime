<?php

namespace ApiGoat\Tests\Mail;

require_once __DIR__ . '/FakeTokenSource.php';

use ApiGoat\Mail\Connector\GraphConnector;
use ApiGoat\Mail\DraftStore;
use ApiGoat\Mail\PartReader;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;
use PHPUnit\Framework\TestCase;

final class GraphConnectorDraftTest extends TestCase
{
    private array $calls = [];
    private array $routes = [];
    private FakeTokenSource $tokens;

    protected function setUp(): void
    {
        $this->calls  = [];
        $this->routes = [];
        $this->tokens = new FakeTokenSource();
    }

    private function connector(): GraphConnector
    {
        $http = function (string $method, string $url, array $headers, ?string $body) {
            $this->calls[] = compact('method', 'url', 'headers', 'body');
            foreach ($this->routes as $needle => $resp) {
                if (str_contains($method . ' ' . $url, (string) $needle)) {
                    if (!is_string($resp['body'])) $resp['body'] = json_encode($resp['body']);
                    return $resp + ['headers' => ''];
                }
            }
            return ['status' => 500, 'headers' => '', 'body' => 'no route for ' . $url];
        };
        return new GraphConnector($this->tokens, '/me', $http);
    }

    public function test_implements_draft_store_and_part_reader(): void
    {
        $c = $this->connector();
        $this->assertInstanceOf(DraftStore::class, $c);
        $this->assertInstanceOf(PartReader::class, $c);
    }

    public function test_append_draft_posts_base64_mime_to_the_drafts_folder_and_returns_the_id(): void
    {
        $this->routes['POST https://graph.microsoft.com/v1.0/me/mailFolders/drafts/messages'] = ['status' => 201, 'body' => ['id' => 'D1']];
        $this->assertSame('D1', $this->connector()->appendDraft('drafts', "Subject: s\r\n\r\nb"));
        $this->assertSame("Subject: s\r\n\r\nb", base64_decode((string) $this->calls[0]['body']));
        $this->assertContains('Content-Type: text/plain', $this->calls[0]['headers']);
    }

    public function test_find_by_message_id_filters_on_internetMessageId(): void
    {
        $this->routes['/mailFolders/sentitems/messages?'] = ['status' => 200, 'body' => ['value' => [['id' => 'S1']]]];
        $this->assertSame('S1', $this->connector()->findByMessageId('sentitems', '<d1@me.com>'));
        $this->assertStringContainsString(rawurlencode("internetMessageId eq '<d1@me.com>'"), $this->calls[0]['url']);
    }

    public function test_find_by_message_id_null_when_empty_and_quotes_doubled(): void
    {
        $this->routes['/mailFolders/inbox/messages?'] = ['status' => 200, 'body' => ['value' => []]];
        $this->assertNull($this->connector()->findByMessageId('inbox', "<o'x@me.com>"));
        $this->assertStringContainsString(rawurlencode("internetMessageId eq '<o''x@me.com>'"), $this->calls[0]['url']);
    }

    public function test_delete_draft_refuses_another_message(): void
    {
        $this->routes['/me/messages/D1?'] = ['status' => 200, 'body' => ['internetMessageId' => '<other@me.com>', 'isDraft' => true]];
        $this->expectException(ValidationRejected::class);
        $this->expectExceptionCode(409);
        try {
            $this->connector()->deleteDraft('D1', 'drafts', '<d1@me.com>');
        } finally {
            $this->assertCount(1, $this->calls, 'no DELETE was sent');
        }
    }

    public function test_delete_draft_deletes_when_message_id_matches(): void
    {
        $this->routes['GET https://graph.microsoft.com/v1.0/me/messages/D1?'] = ['status' => 200, 'body' => ['internetMessageId' => '<d1@me.com>', 'isDraft' => true]];
        $this->routes['DELETE https://graph.microsoft.com/v1.0/me/messages/D1'] = ['status' => 204, 'body' => ''];
        $this->connector()->deleteDraft('D1', 'drafts', '<d1@me.com>');
        $this->assertSame('DELETE', $this->calls[1]['method']);
    }

    public function test_delete_draft_gone_is_transient_404(): void
    {
        $this->routes['/me/messages/D1?'] = ['status' => 404, 'body' => ''];
        try {
            $this->connector()->deleteDraft('D1', 'drafts', '<d1@me.com>');
            $this->fail('expected TransientError');
        } catch (TransientError $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function test_delete_draft_refuses_unresolved_id_before_any_call(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->connector()->deleteDraft('unresolved:x', 'drafts', '<d1@me.com>');
        } finally {
            $this->assertCount(0, $this->calls);
        }
    }

    public function test_send_raw_posts_base64_mime_to_sendMail(): void
    {
        $this->routes['POST https://graph.microsoft.com/v1.0/me/sendMail'] = ['status' => 202, 'body' => ''];
        $this->connector()->sendRaw("Subject: s\r\n\r\nb");
        $this->assertSame("Subject: s\r\n\r\nb", base64_decode((string) $this->calls[0]['body']));
    }

    public function test_fetch_part_downloads_the_mime_once_and_decodes(): void
    {
        $this->routes['/me/messages/M1/$value'] = ['status' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/graph/with-pdf.eml')];
        $c = $this->connector();
        $out = '';
        $leaves = $c->fetchStructure('M1');
        $n = $c->fetchPart('M1', $leaves[1]['section'], $leaves[1]['encoding'], 1 << 20, function (string $b) use (&$out) { $out .= $b; });
        $this->assertSame(strlen($out), $n);
        $this->assertStringStartsWith('%PDF', $out);
        $this->assertCount(1, $this->calls);
    }

    public function test_fetch_part_unknown_section_is_validation_rejected_404(): void
    {
        $this->routes['/me/messages/M1/$value'] = ['status' => 200, 'body' => file_get_contents(__DIR__ . '/fixtures/graph/with-pdf.eml')];
        try {
            $this->connector()->fetchPart('M1', '9', '7bit', 100, fn ($b) => null);
            $this->fail('expected ValidationRejected');
        } catch (ValidationRejected $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function test_fetch_structure_of_a_deleted_message_is_validation_rejected_404(): void
    {
        $this->routes['/me/messages/M1/$value'] = ['status' => 404, 'body' => ''];
        try {
            $this->connector()->fetchStructure('M1');
            $this->fail('expected ValidationRejected');
        } catch (ValidationRejected $e) {
            $this->assertSame(404, $e->getCode());
        }
    }
}
