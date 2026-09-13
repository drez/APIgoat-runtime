<?php

namespace ApiGoat\Domains\ThreadedList;

/**
 * One page of a threaded list: which conversations are on it, what each one
 * shows, and how big each really is.
 *
 * `counts` is the thread's FULL size even when a filter is active — a filtered
 * count would tell the reader a conversation is smaller than it is.
 *
 * getList.php assigns a ThreadPage directly to the same `$pmpoData` local that
 * otherwise holds a `PropelModelPager`, so it has to satisfy every contract
 * that variable is put through downstream: `isEmpty()` (empty-state check),
 * iteration over the row loop (via IteratorAggregate — getList.php only calls
 * `->getResults()` when the object's class is literally 'PropelModelPager',
 * so a ThreadPage is iterated directly), and `getPager()`'s pager-UI contract
 * (`haveToPaginate()`, `getLastPage()`, `getMaxPerPage()`).
 */
final class ThreadPage implements \IteratorAggregate, \Countable
{
    /** 0-based index of the row most recently handed out by the iterator below. */
    private int $position = 0;

    /**
     * @param string[]              $keys           thread keys, in page order
     * @param array<string,object>  $representative key => the thread's newest row
     * @param array<string,int>     $counts         key => full thread size
     * @param int                   $page           1-based page number requested
     * @param int                   $perPage        page size requested
     */
    public function __construct(
        public readonly array $keys,
        public readonly array $representative,
        public readonly array $counts,
        public readonly int $totalThreads,
        private readonly int $page = 1,
        private readonly int $perPage = 1,
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

    /** Mirrors PropelModelPager::isEmpty() — no threads on this page. */
    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /** Countable: number of threads on THIS page (not totalThreads). */
    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * IteratorAggregate: the row loop iterates representative rows directly.
     * The generic list-row emitter (getList.php) calls $pcData->getPosition()
     * on whatever it is handed, mid-iteration, for every row's data-iterator
     * attribute — mirroring PropelObjectCollection, where the collection
     * being iterated IS what tracks the cursor. A plain array or a
     * position-less iterator (e.g. a bare ArrayIterator) has no such method
     * and fatals mid-render, so this returns a Generator instead: as each
     * row is yielded it updates $this->position, which getPosition() below
     * reads back.
     */
    public function getIterator(): \Iterator
    {
        foreach ($this->rows() as $i => $row) {
            $this->position = $i;
            yield $row;
        }
    }

    /** Mirrors PropelObjectCollection::getPosition(): current row's 0-based index. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Mirrors PropelModelPager::getMaxPerPage(). */
    public function getMaxPerPage(): int
    {
        return $this->perPage;
    }

    /** Mirrors PropelModelPager::getLastPage(). */
    public function getLastPage(): int
    {
        $perPage = max(1, $this->perPage);
        return max(1, (int) ceil($this->totalThreads / $perPage));
    }

    /** Mirrors PropelModelPager::haveToPaginate(). */
    public function haveToPaginate(): bool
    {
        return $this->totalThreads > $this->perPage;
    }
}
