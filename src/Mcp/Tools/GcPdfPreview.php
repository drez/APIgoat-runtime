<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Pdf\PresetRenderer;
use ApiGoat\Sessions\AuthySession;

/**
 * gc_pdf_preview — the printable HTML of a with_pdf record, on demand,
 * WITHOUT touching storage. Returns the document plus everything needed to
 * make changes before generating: which template rows fed it (editable as
 * the normal Template entity) and the placeholder→value map (fix record
 * fields vs template HTML). Read-only.
 */
class GcPdfPreview extends AbstractPdfTool
{
    /** Keep tool results well under typical MCP payload limits. */
    private const HTML_CAP = 250000;

    public function name(): string { return 'gc_pdf_preview'; }

    public function description(): string
    {
        return 'Preview the printable HTML of a PDF-enabled record before generating its PDF. '
            . 'Returns the rendered document, the template rows used (edit them via the Template entity '
            . 'to change the layout) and the placeholder values (edit the record to change the data). '
            . 'Workflow: preview → make changes → preview again → gc_regenerate_pdf.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['table', 'id'], 'properties' => [
            'table'    => ['type' => 'string', 'description' => 'PDF-enabled table (e.g. billing) or its entity name'],
            'id'       => ['type' => 'integer', 'description' => 'Primary key of the record'],
            'template' => ['type' => 'integer', 'description' => 'Optional id_template to pick a specific header/footer variant'],
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
        $record = $this->loadRecord($entry, $id, $session, 'r');

        $templateId = isset($args['template']) ? (int) $args['template'] : null;
        try {
            $doc = PresetRenderer::render($record, $entry, $templateId);
        } catch (\Throwable $e) {
            throw new ToolError('Preview failed: ' . $e->getMessage(), [], 'internal');
        }

        $html = (string) $doc['html'];
        $truncated = false;
        if (strlen($html) > self::HTML_CAP) {
            $html = substr($html, 0, self::HTML_CAP) . "\n<!-- gc_pdf_preview: truncated -->";
            $truncated = true;
        }

        return $this->ok([
            'table'          => strtolower((string) $entry['table']),
            'id'             => $id,
            'lang'           => $doc['lang'],
            'html'           => $html,
            'truncated'      => $truncated,
            'templates_used' => $doc['templates_used'],
            'placeholders'   => $doc['placeholders'],
        ]);
    }
}
