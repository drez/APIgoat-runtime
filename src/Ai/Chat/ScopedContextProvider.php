<?php

namespace ApiGoat\Ai\Chat;

/**
 * A ContextProvider whose grounding can be narrowed to one named SCOPE — a
 * sub-context of the tenant the user picks in the chat panel (apigmail: one
 * mailbox group). The runtime knows nothing about what a scope means: it
 * shows the choices, carries the chosen value back, and keeps one chat
 * history per scope (ChatSessionStore::useScope() starts a fresh one when
 * the value changes, so an answer given in one scope is never replayed as
 * history in another).
 *
 * Contract:
 *  - scopes() lists the choices for this tenant, in display order, as
 *    [{value: string, label: string}]. The value "" means "everything" (the
 *    unscoped behaviour); list it first when you offer it. An EMPTY list
 *    means "no switcher": the panel hides it and the endpoint refuses any
 *    non-empty scope.
 *  - retrieve() receives the chosen value as $options['scope'] (absent or ""
 *    = everything). The emitted endpoint only forwards a value scopes()
 *    listed, but the provider must still validate it and throw
 *    ChatScopeInvalid rather than widen to everything — the endpoint maps
 *    that to a 400 with the exception's message.
 *
 * A provider implementing only ContextProvider keeps working unchanged: it
 * is called with three arguments and never receives a scope.
 */
interface ScopedContextProvider extends ContextProvider
{
    /**
     * @param array<int,array{role:string,content:string}> $history
     * @param array{scope?:string} $options
     * @throws ChatScopeInvalid when $options['scope'] is not a scope of this tenant
     */
    public function retrieve(string $question, array $history, ?int $idTenant, array $options = []): ContextBundle;

    /**
     * @return array<int,array{value:string,label:string}> the switcher's choices; [] hides it
     */
    public function scopes(?int $idTenant): array;
}
