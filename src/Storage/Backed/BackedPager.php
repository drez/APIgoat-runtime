<?php

namespace ApiGoat\Storage\Backed;

/**
 * Mirrors the shape of Propel\Runtime\Util\PropelModelPager (Propel 1's
 * \PropelModelPager) so generated list templates ($pmpoData->getNbResults(),
 * ->haveToPaginate(), ->getResults(), foreach over the pager, etc.) work
 * unchanged for drive-backed entities.
 *
 * Backends with cursor-based pagination (Drive uses page_token) only fetch
 * the requested page; getNbResults() returns the running known total
 * (count(items) so far) until the cursor exhausts.
 */
class BackedPager implements \IteratorAggregate, \Countable
{
    private BackedQuery $query;
    private int $page;
    private int $perPage;

    /** @var ?array<int, BackedEntity> */
    private ?array $results = null;

    /** @var ?int Total when the backend can report one; else null (cursor mode). */
    private ?int $total = null;

    public function __construct(BackedQuery $query, int $page = 1, int $perPage = 20)
    {
        $this->query = $query;
        $this->page = max(1, $page);
        $this->perPage = max(1, $perPage);
    }

    public function getResults(): array
    {
        if ($this->results === null) {
            $this->results = $this->query->paginateFetch($this->page, $this->perPage);
        }
        return $this->results;
    }

    public function getNbResults(): int
    {
        if ($this->total !== null) {
            return $this->total;
        }
        // No exact total → return known-so-far. Good enough for the "N
        // results" badge; the pager nav still works via haveToPaginate().
        return count($this->getResults());
    }

    public function haveToPaginate(): bool
    {
        // Cursor-based: we paginate if the current page filled up — there's
        // likely more. If the storage returns a page_token, that's an even
        // stronger signal but we can't see it here without re-querying.
        return count($this->getResults()) >= $this->perPage
            || $this->page > 1;
    }

    public function getPage(): int             { return $this->page; }
    public function getMaxPerPage(): int       { return $this->perPage; }
    public function getNbPages(): int          { return max(1, (int) ceil($this->getNbResults() / $this->perPage)); }
    public function getFirstPage(): int        { return 1; }
    public function getLastPage(): int         { return $this->getNbPages(); }
    public function getNextPage(): int         { return min($this->page + 1, $this->getLastPage()); }
    public function getPreviousPage(): int     { return max($this->page - 1, 1); }
    public function isFirstPage(): bool        { return $this->page === 1; }
    public function isLastPage(): bool         { return $this->page >= $this->getLastPage(); }
    public function getCurrentMaxLink(): int   { return min($this->page * $this->perPage, $this->getNbResults()); }
    public function getCurrentMinLink(): int   { return ($this->page - 1) * $this->perPage + 1; }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->getResults());
    }

    public function count(): int
    {
        return $this->getNbResults();
    }
}
