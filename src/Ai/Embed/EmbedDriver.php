<?php

namespace ApiGoat\Ai\Embed;

use ApiGoat\Ai\AiProfile;

/**
 * One embedding call against whatever AiProfile says.
 *
 * Mirror of ApiGoat\Ai\Chat\ChatDriver, file for file, so anyone who has read
 * one has read both.
 *
 * Implementations: OllamaEmbed (the /v1 shim on a local box), OpenAiEmbed
 * (the cloud, same wire shape, real auth).
 */
interface EmbedDriver
{
    /**
     * Embed $texts, in order.
     *
     * @param string[] $texts one input per returned vector, in the order given
     * @param array<string,mixed> $opts model (override of $profile->embedModel()),
     *   dimensions (override of $profile->embedDimensions(); 0 disables the
     *   width check), timeout, extra (top-level body keys)
     * @throws EmbedFailed when the provider does not answer with usable vectors
     */
    public function embed(AiProfile $profile, array $texts, array $opts = []): EmbedResult;
}
