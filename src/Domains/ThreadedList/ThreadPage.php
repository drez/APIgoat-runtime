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

    /** IteratorAggregate: the row loop iterates representative rows directly. */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rows());
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
