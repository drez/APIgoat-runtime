<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Pdf\PdfGenerator;
use ApiGoat\Pdf\PdfStaleness;
use ApiGoat\Sessions\AuthySession;

/**
 * gc_regenerate_pdf — regenerate a PDF-enabled record's saved PDF, overwriting
 * the single stored copy in place (local child row and/or Drive). Use after
 * editing the record so the stored copy is refreshed. Preview/adjust first
 * with gc_pdf_preview.
 */
class GcRegeneratePdf extends AbstractPdfTool
{
    public function name(): string { return 'gc_regenerate_pdf'; }

    public function description(): string
    {
        return 'Regenerate the saved PDF of a PDF-enabled record — ONLY when the user explicitly asks for '
            . 'a PDF (generate/refresh/send); to view or show a document use gc_pdf_preview instead. '
            . 'Overwrites the single stored copy in place. Preview/adjust first with gc_pdf_preview.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['table', 'id'], 'properties' => [
            'table'    => ['type' => 'string', 'description' => 'PDF-enabled table (e.g. billing) or its entity name'],
            'id'       => ['type' => 'integer', 'description' => 'Primary key of the record'],
            'template' => ['type' => 'integer', 'description' => 'Optional id_template header/footer variant'],
            'lang'     => ['type' => 'string', 'description' => 'Generate in this locale (e.g. en_US) instead of the record\'s document language; persists as the saved copy\'s pdf_lang'],
        ]];
    }

    public function requiredRight(): ?array { return null; } // per-entity check in handle()

    public function handle(array $args, AuthySession $session): array
    {
        $table = (string) ($args['table'] ?? '');
        $id    = (int) ($args['id'] ?? 0);
        if ($table === '' || $id <= 0) {
            throw new ToolError("'table' and a positive 'id' are required.", [], 'bad_request');
        }

        $entry  = $this->entryFor($table);
        $record = $this->loadRecord($entry, $id, $session, 'w');
        $email  = $this->workspaceEmail($session);

        // Staleness BEFORE regenerating, so callers can report whether the
        // copy actually needed the refresh.
        $wasStale = !PdfStaleness::savedCopyIsCurrent($record, $entry);

        $lang = $this->assertValidLang($args);
        try {
            $templateId = isset($args['template']) ? (int) $args['template'] : null;
            $res = PdfGenerator::generate($record, $entry, $templateId, $email, $lang);
        } catch (\RuntimeException $e) {
            throw new ToolError($e->getMessage(), [], 'bad_request');
        } catch (\Throwable $e) {
            throw new ToolError('Generate failed: ' . $e->getMessage(), [], 'internal');
        }

        return $this->ok([
            'table'     => strtolower((string) $entry['table']),
            'id'        => $id,
            'name'      => $res['name'],
            'url'       => $res['url'],
            'lang'      => $res['lang'],
            'was_stale' => $wasStale,
        ]);
    }
}
