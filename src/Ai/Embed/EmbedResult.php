<?php

namespace ApiGoat\Ai\Embed;

use ApiGoat\Ai\AiConfig;

/**
 * A normalised embedding answer: the vectors IN THE ORDER THE TEXTS WERE
 * GIVEN, the token counts whichever name the provider used, and the raw body
 * for anything else.
 *
 * Mirror of ApiGoat\Ai\Chat\ChatResult.
 */
final class EmbedResult
{
    private int $status;
    /** @var array<int,array<int,float>> */
    private array $vectors;
    /** @var array{input_tokens:int,output_tokens:int} */
    private array $usage;
    private int $latencyMs;
    private string $model;
    /** @var mixed */
    private $raw;
    private string $transportError;

    /**
     * @param array<int,array<int,float>> $vectors already ordered
     * @param array<string,mixed> $usage prompt_tokens/completion_tokens or input_tokens/output_tokens
     * @param mixed $raw the decoded response body as the provider sent it
     */
    public function __construct(
        int $status,
        array $vectors,
        array $usage = [],
        int $latencyMs = 0,
        string $model = '',
        $raw = null,
        string $transportError = ''
    ) {
        $this->status = $status;
        $this->vectors = \array_values($vectors);
        $this->usage = [
            'input_tokens'  => (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
        ];
        $this->latencyMs = $latencyMs;
        $this->model = $model;
        $this->raw = $raw;
        $this->transportError = $transportError;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** 2xx, no transport error, and at least one vector came back. */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300
            && $this->transportError === ''
            && $this->vectors !== [];
    }

    /** @return array<int,array<int,float>> one vector per input text, in input order */
    public function vectors(): array
    {
        return $this->vectors;
    }

    /** The first vector, for the common one-text call. */
    public function vector(): ?array
    {
        return $this->vectors[0] ?? null;
    }

    /** The width of the returned vectors (0 when none came back). */
    public function dimensions(): int
    {
        return isset($this->vectors[0]) ? \count($this->vectors[0]) : 0;
    }

    public function count(): int
    {
        return \count($this->vectors);
    }

    /** @return array{input_tokens:int,output_tokens:int} */
    public function usage(): array
    {
        return $this->usage;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    /** The model the provider says answered (may be '' when it does not say). */
    public function model(): string
    {
        return $this->model;
    }

    /** @return mixed */
    public function raw()
    {
        return $this->raw;
    }

    public function transportError(): string
    {
        return $this->transportError;
    }

    /**
     * USD for this call at the given per-million prices. Embedding endpoints
     * bill input only, but the shape is kept identical to ChatResult's.
     *
     * @param array{input_per_m:float,output_per_m:float} $prices
     */
    public function costUsd(array $prices): float
    {
        return AiConfig::priceOf(
            $this->usage['input_tokens'],
            $this->usage['output_tokens'],
            (float) ($prices['input_per_m'] ?? 0.0),
            (float) ($prices['output_per_m'] ?? 0.0)
        );
    }
}
