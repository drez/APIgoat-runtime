<?php
namespace ApiGoat\Mcp;

interface McpTool
{
    public function name(): string;          // unique; custom tools must NOT start with 'crm_'
    public function description(): string;
    public function inputSchema(): array;    // JSON Schema (object)
    /** @return array|null [entity, letter] RBAC gate (e.g. ['Quote','w']) or null for no gate */
    public function requiredRight(): ?array;
    /** @return array MCP tool result {content:[…], isError?:bool}; throw ToolError for a mapped isError */
    public function handle(array $args, \ApiGoat\Sessions\AuthySession $session): array;
}
