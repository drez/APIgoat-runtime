<?php

namespace ApiGoat\Domains\ThreadedList;

/**
 * One page of a threaded list: which conversations are on it, what each one
 * shows, and how big each really is.
 *
 * `counts` is the thread's FULL size even when a filter is active — a filtered
 * count would tell the reader a conversation is smaller than it is.
 */
final class ThreadPage
{
    /**
     * @param string[]              $keys           thread keys, in page order
     * @param array<string,object>  $representative key => the thread's newest row
     * @param array<string,int>     $counts         key => full thread size
     */
    public function __construct(
        public readonly array $keys,
        public readonly array $representative,
        public readonly array $counts,
        public readonly int $totalThreads,
    ) {
    }

    /** Representative rows in page order. @return object[] */
    public function rows(): array
    {
        $out = [];
        foreach ($this->keys as $k) {
            if (isset($this->representative[$k])) {
                $out[] = $this->representative[$k];
            }
        }
        return $out;
    }
}
