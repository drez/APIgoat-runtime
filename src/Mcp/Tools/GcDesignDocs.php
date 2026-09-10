<?php
namespace ApiGoat\Mcp\Tools;

use ApiGoat\Mcp\DesignManifest;
use ApiGoat\Mcp\ToolError;
use ApiGoat\Sessions\AuthySession;

/**
 * design_docs — the project's curated product documentation for design and
 * assistant tools (feature brief, AI-functions guide, entity/schema
 * overviews). Content is embedded at build time into
 * config/Built/mcp.design.php by the with_mcp behavior (design_docs key),
 * so it is identical on dev and prod. Gated in ToolRegistry::builtins()
 * on DesignManifest::available(). No RBAC gate: docs describe the product,
 * not its data.
 */
class GcDesignDocs implements \ApiGoat\Mcp\McpTool
{
    public function name(): string { return 'design_docs'; }

    public function description(): string
    {
        return 'Project documentation for understanding the product: call without arguments '
             . 'to list the available docs with a one-line summary each, then pass doc to '
             . 'read one in full (markdown). Covers features, how the AI functions work, '
             . 'and the data model.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'doc' => ['type' => 'string',
                'description' => 'Doc name from the no-argument listing (case-insensitive); omit to list'],
        ]];
    }

    public function requiredRight(): ?array { return null; }

    public function handle(array $args, AuthySession $session): array
    {
        $docs = DesignManifest::docs();
        $want = isset($args['doc']) && \is_string($args['doc']) ? \trim($args['doc']) : '';
        if ($want === '') {
            $list = [];
            foreach ($docs as $name => $d) {
                $list[] = ['doc' => (string) $name, 'summary' => (string) ($d['summary'] ?? '')];
            }
            return ['content' => [['type' => 'text', 'text' => \json_encode(
                ['docs' => $list, 'hint' => 'Pass doc to read one in full.'],
                JSON_UNESCAPED_SLASHES
            )]]];
        }
        foreach ($docs as $name => $d) {
            if (\strcasecmp((string) $name, $want) === 0) {
                return ['content' => [['type' => 'text', 'text' => (string) ($d['content'] ?? '')]]];
            }
        }
        throw new ToolError(
            "Unknown doc '{$want}'. Available: " . \implode(', ', \array_map('strval', \array_keys($docs))),
            [],
            'not_found'
        );
    }
}
