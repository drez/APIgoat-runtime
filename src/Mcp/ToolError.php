<?php
namespace ApiGoat\Mcp;

final class ToolError extends \RuntimeException
{
    /** @param string[] $messages  @param ?string $kind validation|not_permitted|not_found|bad_request */
    public function __construct(string $message, public array $messages = [], public ?string $kind = null)
    {
        parent::__construct($message);
    }
}
