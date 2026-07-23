<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;
use ApiGoat\Stripe\CheckoutService;
use ApiGoat\Stripe\StripeManifest;

/**
 * stripe_payment_link — create (or re-issue) a Stripe payment link for a
 * payable record. Gated in ToolRegistry::builtins() on StripeManifest::available().
 */
class GcStripePaymentLink extends AbstractCrmTool
{
    public function name(): string { return 'stripe_payment_link'; }

    public function description(): string
    {
        return 'Create (or re-issue) a Stripe payment link for a payable record. Returns the public pay-page URL to hand to the payer.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['table', 'id'], 'properties' => [
            'table' => ['type' => 'string', 'description' => 'Payable table name, e.g. billing'],
            'id'    => ['type' => 'integer', 'description' => 'Record id'],
        ]];
    }

    public function requiredRight(): ?array { return null; } // per-entity 'w' check in handle()

    public function handle(array $args, AuthySession $session): array
    {
        $table = \strtolower((string) ($args['table'] ?? ''));
        $entry = StripeManifest::payable($table);
        if ($entry === null) {
            throw new ToolError('Not a Stripe-payable table. Available: ' . \implode(', ', \array_keys(StripeManifest::all()['payables'] ?? [])), [], 'not_found');
        }
        $this->assertEntityPermitted($this->catalog($session), $entry['entity'], 'update');
        $rec = $session->loadPkScoped("\\App\\{$entry['entity']}Query", (int) ($args['id'] ?? 0), $entry['entity'], 'w');
        if ($rec === null) {
            throw new ToolError('Record not found or not permitted', [], 'not_found');
        }
        $out = CheckoutService::createForRecord($rec, $table);
        return $this->ok(['pay_url' => $out['pay_url'], 'payment_id' => $out['payment_id']]);
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
