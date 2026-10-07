<?php

namespace ApiGoat\Ai\Chat;

/**
 * A ScopedContextProvider was handed a scope that is not one of the tenant's
 * (another tenant's id, a deleted group, garbage). The message is shown to
 * the user, so keep it readable and free of internal detail. The emitted
 * `<Model>/chat` endpoint answers 400 with it — never a silent fallback to
 * the unscoped context.
 */
final class ChatScopeInvalid extends \InvalidArgumentException
{
}
