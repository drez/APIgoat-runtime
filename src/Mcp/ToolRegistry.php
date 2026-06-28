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
        $custom = $extraTools ?? $this->discover();
        foreach ($custom as $t) {
            $this->add($t);
        }
    }

    /** @return McpTool[] */
    private function builtins(): array
    {
        return [
            new Tools\CrmDescribe(),
            new Tools\CrmList(),
            new Tools\CrmGet(),
            new Tools\CrmCreate(),
            new Tools\CrmUpdate(),
            new Tools\CrmDelete(),
        ];
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
                if (class_exists($class) && is_subclass_of($class, McpTool::class)) {
                    $found[] = new $class();
                }
            }
            $manifest = _BASE_DIR . 'config/mcp.php';
            if (is_file($manifest)) {
                $cfg = require $manifest;
                foreach (($cfg['tools'] ?? []) as $class) {
                    if (class_exists($class) && is_subclass_of($class, McpTool::class)) {
                        $found[] = new $class();
                    }
                }
                $this->disabled = (array) ($cfg['disabled'] ?? []);
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
