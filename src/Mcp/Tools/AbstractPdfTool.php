<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Pdf\PdfManifest;
use ApiGoat\Sessions\AuthySession;

/**
 * Shared plumbing for the generic with_pdf MCP tools (gc_pdf_preview /
 * gc_regenerate_pdf). These are registered only when the build-emitted
 * manifest config/Built/pdf.php exists (see ToolRegistry::builtins()).
 */
abstract class AbstractPdfTool extends AbstractCrmTool
{
    /** Manifest entry for a table/entity arg, or a clear ToolError. */
    protected function entryFor(string $table): array
    {
        $entry = PdfManifest::entryFor($table);
        if ($entry === null) {
            $known = implode(', ', array_keys(PdfManifest::all()));
            throw new ToolError(
                "Table '{$table}' has no with_pdf configuration. PDF-enabled tables: {$known}.",
                [],
                'not_found'
            );
        }
        return $entry;
    }

    /** RBAC + tenant/owner-scoped record load ('r' or 'w' scope). */
    protected function loadRecord(array $entry, int $id, AuthySession $session, string $right): object
    {
        $entity = (string) $entry['entity'];
        $this->assertEntityPermitted($this->catalog($session), $entity, $right === 'w' ? 'update' : 'read');
        $record = $session->loadPkScoped("\\App\\{$entity}Query", $id, $entity, $right);
        if ($record === null) {
            throw new ToolError("{$entity} #{$id} not found or not permitted.", [], 'not_found');
        }
        return $record;
    }

    /** Caller's Google Workspace email ('' when not configured/available). */
    protected function workspaceEmail(AuthySession $session): string
    {
        if (empty($session->authyId)) {
            return '';
        }
        $authy = \App\AuthyQuery::create()->findPk($session->authyId);
        if ($authy === null || !method_exists($authy, 'getGoogleWorkspaceEmail')) {
            return '';
        }
        return (string) ($authy->getGoogleWorkspaceEmail() ?: '');
    }

    /** Success payload in MCP content shape. */
    protected function ok(array $payload): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($payload, JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
        ];
    }
}
