<?php
namespace ApiGoat\Mcp;

use ApiGoat\Sessions\AuthySession;

class ToolRegistry
{
    /** @var McpTool[] keyed by name */
    private array $tools = [];

    /** @param McpTool[]|null $extraTools test/explicit injection; null => glob + manifest discovery */
    public function __construct(?array $extraTools = null)
    {
        foreach ($this->builtins() as $t) {
            $this->tools[$t->name()] = $t;
        }
        $manifest = $this->manifest();
        $this->disabled = (array) ($manifest['disabled'] ?? []);
        $instructions = $manifest['instructions'] ?? null;
        $this->instructions = (is_string($instructions) && trim($instructions) !== '') ? $instructions : null;
        $custom = $extraTools ?? $this->discover();
        foreach ($custom as $t) {
            $this->add($t);
        }
    }

    private ?array $manifestCache = null;

    /** config/mcp.php manifest contents ([] when absent). */
    private function manifest(): array
    {
        if ($this->manifestCache === null) {
            $this->manifestCache = [];
            if (defined('_BASE_DIR') && is_file(_BASE_DIR . 'config/mcp.php')) {
                $cfg = require _BASE_DIR . 'config/mcp.php';
                if (is_array($cfg)) {
                    $this->manifestCache = $cfg;
                }
            }
        }
        return $this->manifestCache;
    }

    /** Project-authored model guidance from config/mcp.php 'instructions' (null = none). */
    public function instructions(): ?string
    {
        return $this->instructions;
    }

    private ?string $instructions = null;

    /** @return McpTool[] */
    private function builtins(): array
    {
        $tools = [
            new Tools\CrmDescribe(),
            new Tools\CrmList(),
            new Tools\CrmGet(),
            new Tools\CrmCreate(),
            new Tools\CrmUpdate(),
            new Tools\CrmDelete(),
        ];
        // Generic with_pdf tools — present exactly when the build emitted a
        // pdf manifest (some table declares the with_pdf behavior).
        if (\ApiGoat\Pdf\PdfManifest::available()) {
            $tools[] = new Tools\GcPdfPreview();
            $tools[] = new Tools\GcRegeneratePdf();
        }
        // Generic Stripe tools — present exactly when the build emitted a
        // stripe manifest (some table declares the with_stripe behavior).
        if (\ApiGoat\Stripe\StripeManifest::available()) {
            $tools[] = new Tools\GcStripePaymentLink();
            $tools[] = new Tools\GcStripeStatus();
        }
        return $tools;
    }

    /** Built-ins own the crm_ prefix; a custom tool that shadows is rejected (logged, skipped). */
    private function add(McpTool $t): void
    {
        $name = $t->name();
        if (str_starts_with($name, 'crm_') || isset($this->tools[$name])) {
            error_log("[mcp] custom tool '{$name}' rejected: reserved crm_ prefix or name collision");
            return;
        }
        $this->tools[$name] = $t;
    }

    /** Glob editable project tools + the optional config/mcp.php manifest. */
    private function discover(): array
    {
        $found = [];
        if (defined('_BASE_DIR')) {
            foreach (glob(_BASE_DIR . 'src/App/Mcp/Tools/*Tool.php') ?: [] as $file) {
                $class = '\\App\\Mcp\\Tools\\' . basename($file, '.php');
                if (class_exists($class) && is_subclass_of($class, McpTool::class)
                    && !(new \ReflectionClass($class))->isAbstract()) {
                    $found[] = new $class();
                }
            }
            foreach (($this->manifest()['tools'] ?? []) as $class) {
                if (class_exists($class) && is_subclass_of($class, McpTool::class)
                    && !(new \ReflectionClass($class))->isAbstract()) {
                    $found[] = new $class();
                }
            }
        }
        return $found;
    }

    private array $disabled = [];

    public function get(string $name): ?McpTool
    {
        if (in_array($name, $this->disabled, true)) {
            return null;
        }
        return $this->tools[$name] ?? null;
    }

    /** @return McpTool[] */
    public function all(): array
    {
        return $this->tools;
    }

    /** @return array<int,array{name:string,description:string,inputSchema:array}> RBAC-filtered */
    public function list(AuthySession $session): array
    {
        $out = [];
        foreach ($this->tools as $name => $tool) {
            if (in_array($name, $this->disabled, true)) {
                continue;
            }
            $right = $tool->requiredRight();
            if ($right !== null) {
                [$entity, $letter] = $right;
                $granted = $session->isAdmin() || $session->hasRights($entity, $letter) !== false;
                if (!$granted) {
                    continue;   // omit tools the session can't use
                }
            }
            $out[] = ['name' => $tool->name(), 'description' => $tool->description(), 'inputSchema' => $tool->inputSchema()];
        }
        return $out;
    }
}
