<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Pdf\PdfGenerator;
use ApiGoat\Pdf\PdfStaleness;
use ApiGoat\Sessions\AuthySession;

/**
 * gc_regenerate_pdf — regenerate a PDF-enabled record's CURRENT saved PDF
 * (replace-in-place across the configured stores; local child row and/or
 * Drive). backup:true first snapshots the current copy as "_bak<N>" (the only
 * way versions are kept); wipe:true deletes every _bak copy so only the
 * latest remains — destructive, so it requires confirm:true.
 */
class GcRegeneratePdf extends AbstractPdfTool
{
    public function name(): string { return 'gc_regenerate_pdf'; }

    public function description(): string
    {
        return 'Regenerate the saved PDF of a PDF-enabled record (use after editing it — the stored copy '
            . 'goes stale). Replaces the current copy in place. backup:true snapshots the current copy as '
            . '_bak<N> first; wipe:true (with confirm:true) clears all previous _bak versions and keeps '
            . 'only the latest. Preview/adjust first with gc_pdf_preview.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['table', 'id'], 'properties' => [
            'table'    => ['type' => 'string', 'description' => 'PDF-enabled table (e.g. billing) or its entity name'],
            'id'       => ['type' => 'integer', 'description' => 'Primary key of the record'],
            'template' => ['type' => 'integer', 'description' => 'Optional id_template header/footer variant'],
            'backup'   => ['type' => 'boolean', 'description' => 'Snapshot the current copy as _bak<N> before regenerating'],
            'wipe'     => ['type' => 'boolean', 'description' => 'Delete every _bak backup copy, keeping only the regenerated current. Deletes files — requires confirm:true.'],
            'confirm'  => ['type' => 'boolean', 'description' => 'Required true when wipe:true (destructive).'],
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

        $wipe = ($args['wipe'] ?? false) === true;
        if ($wipe && ($args['confirm'] ?? false) !== true) {
            throw new ToolError(
                'wipe:true deletes every _bak backup copy of this document. Set confirm:true to proceed '
                . '(or omit wipe to keep the backups).',
                [],
                'bad_request'
            );
        }

        $entry  = $this->entryFor($table);
        $record = $this->loadRecord($entry, $id, $session, 'w');
        $email  = $this->workspaceEmail($session);

        // Staleness BEFORE regenerating, so callers can report whether the
        // copy actually needed the refresh.
        $wasStale = !PdfStaleness::savedCopyIsCurrent($record, $entry);

        try {
            $backedUp = null;
            if (($args['backup'] ?? false) === true) {
                try {
                    $backedUp = PdfGenerator::backup($record, $entry, $email)['name'];
                } catch (\RuntimeException $e) {
                    $backedUp = null; // nothing to back up yet — proceed to generate
                }
            }

            $templateId = isset($args['template']) ? (int) $args['template'] : null;
            $res = PdfGenerator::generate($record, $entry, $templateId, $email);

            $deleted = 0;
            if ($wipe) {
                $deleted = PdfGenerator::wipeBackups($record, $entry, $email)['deleted'];
            }
        } catch (\RuntimeException $e) {
            throw new ToolError($e->getMessage(), [], 'bad_request');
        } catch (\Throwable $e) {
            throw new ToolError('Generate failed: ' . $e->getMessage(), [], 'internal');
        }

        return $this->ok([
            'table'      => strtolower((string) $entry['table']),
            'id'         => $id,
            'name'       => $res['name'],
            'url'        => $res['url'],
            'lang'       => $res['lang'],
            'backed_up'  => $backedUp,
            'wiped'      => $deleted,
            'was_stale'  => $wasStale,
        ]);
    }
}
