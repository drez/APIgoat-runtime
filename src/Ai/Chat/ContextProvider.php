<?php

namespace ApiGoat\Ai\Chat;

/**
 * The app-side half of a grounded chat: given the question (and what was
 * asked before), return the text the model may answer from and the records
 * that text was built from.
 *
 * The runtime never queries project tables; a project registers its
 * implementation through ChatContext::setProvider() and stays responsible
 * for tenant scoping — the $idTenant handed here is the session's tenant,
 * and every row in the bundle must belong to it.
 */
interface ContextProvider
{
    /**
     * @param string $question the user's current message
     * @param array<int,array{role:string,content:string}> $history prior turns, oldest first
     * @param int|null $idTenant the session tenant (null when the app has none)
     */
    public function retrieve(string $question, array $history, ?int $idTenant): ContextBundle;
}
