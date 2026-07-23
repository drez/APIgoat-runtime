<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;
use ApiGoat\Stripe\StripeDb;
use ApiGoat\Stripe\StripeManifest;

/**
 * stripe_status — payment status of a payable record: its Stripe payments
 * (ledger rows) and their states. Gated in ToolRegistry::builtins() on
 * StripeManifest::available().
 */
class GcStripeStatus extends AbstractCrmTool
{
    public function name(): string { return 'stripe_status'; }

    public function description(): string
    {
        return 'Payment status of a payable record: its Stripe payments (ledger rows) and their states.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['table', 'id'], 'properties' => [
            'table' => ['type' => 'string', 'description' => 'Payable table name'],
            'id'    => ['type' => 'integer', 'description' => 'Record id'],
        ]];
    }

    public function requiredRight(): ?array { return null; } // per-entity 'r' check in handle()

    public function handle(array $args, AuthySession $session): array
    {
        $table = \strtolower((string) ($args['table'] ?? ''));
        $entry = StripeManifest::payable($table);
        if ($entry === null) {
            throw new ToolError('Not a Stripe-payable table', [], 'not_found');
        }
        $this->assertEntityPermitted($this->catalog($session), $entry['entity'], 'read');
        $rec = $session->loadPkScoped("\\App\\{$entry['entity']}Query", (int) ($args['id'] ?? 0), $entry['entity'], 'r');
        if ($rec === null) {
            throw new ToolError('Record not found or not permitted', [], 'not_found');
        }
        $payQ = StripeDb::query('StripePayment');
        $rows = $payQ::create()->filterByPayableTable($table)->filterByPayableId((int) $args['id'])->find();
        $out = [];
        foreach ($rows as $p) {
            $out[] = ['payment_id' => $p->getPrimaryKey(), 'status' => $p->getStatus(),
                'amount' => $p->getAmount() / 100, 'currency' => $p->getCurrency(),
                'receipt_url' => $p->getReceiptUrl(), 'error' => $p->getErrorMessage()];
        }
        return $this->ok(['payments' => $out]);
    }

    /** Success payload in MCP content shape (mirrors AbstractPdfTool::ok()). */
    private function ok(array $payload): array
    {
        return [
            'content' => [['type' => 'text', 'text' => \json_encode($payload, JSON_UNESCAPED_SLASHES)]],
            'isError' => false,
        ];
    }
}
