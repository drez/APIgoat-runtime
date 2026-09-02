<?php

namespace ApiGoat\Ai\Chat;

/**
 * What a ContextProvider hands back: a grounding block for the model and the
 * records it cites.
 *
 * `text` is the block the model reads — plain text, most important facts
 * first, since ChatAssistant keeps the HEAD when it has to cut it down to
 * its budget (~6k chars). `sources` are the citable records: the model is
 * told to cite them by label, and the panel renders them as chips linking
 * to `href` when one is given. `stats` is free-form (counts, timings) for
 * the caller's own logging; the model never sees it.
 */
final class ContextBundle
{
    public string $text;
    /** @var array<int,array{id:string,label:string,href?:string}> */
    public array $sources;
    /** @var array<string,mixed> */
    public array $stats;

    /**
     * @param array<int,array{id:string,label:string,href?:string}> $sources
     * @param array<string,mixed> $stats
     */
    public function __construct(string $text, array $sources = [], array $stats = [])
    {
        $this->text = $text;
        $this->sources = [];
        foreach ($sources as $s) {
            if (!\is_array($s) || !isset($s['label'])) {
                continue;
            }
            $row = [
                'id'    => (string) ($s['id'] ?? $s['label']),
                'label' => (string) $s['label'],
            ];
            if (isset($s['href']) && \is_string($s['href']) && $s['href'] !== '') {
                $row['href'] = $s['href'];
            }
            $this->sources[] = $row;
        }
        $this->stats = $stats;
    }

    public static function empty(): self
    {
        return new self('');
    }
}
