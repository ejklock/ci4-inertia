<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\Pager\Exceptions\PagerException;
use CodeIgniter\Pager\PagerInterface;
use InvalidArgumentException;

/**
 * The pagination position of a scroll prop, either given page by page or read from the pager
 * the model's `paginate()` stored.
 */
final readonly class ScrollMetadata implements ProvidesScrollMetadata
{
    public function __construct(
        private string $pageName,
        private int|string|null $previousPage = null,
        private int|string|null $nextPage = null,
        private int|string|null $currentPage = null,
    ) {
    }

    /**
     * Reads the position of one pager group: the previous page exists after the first one and the
     * next page while the pager reports more results.
     *
     * @throws InvalidArgumentException When the group holds no pagination, because nothing paginated into it.
     */
    public static function fromPager(PagerInterface $pager, string $group = 'default'): self
    {
        try {
            $details = $pager->getDetails($group);
        } catch (PagerException $e) {
            throw new InvalidArgumentException(
                sprintf('The pager group "%s" holds no pagination: paginate before building the response or pass the scroll metadata explicitly.', $group),
                previous: $e,
            );
        }

        $current = $details['currentPage'];

        return new self(
            $details['pageSelector'],
            $current > 1 ? $current - 1 : null,
            $pager->hasMore($group) ? $current + 1 : null,
            $current,
        );
    }

    public function getPageName(): string
    {
        return $this->pageName;
    }

    public function getPreviousPage(): int|string|null
    {
        return $this->previousPage;
    }

    public function getNextPage(): int|string|null
    {
        return $this->nextPage;
    }

    public function getCurrentPage(): int|string|null
    {
        return $this->currentPage;
    }

    /**
     * @return array{pageName: string, previousPage: int|string|null, nextPage: int|string|null, currentPage: int|string|null}
     */
    public function toArray(): array
    {
        return [
            'pageName'     => $this->pageName,
            'previousPage' => $this->previousPage,
            'nextPage'     => $this->nextPage,
            'currentPage'  => $this->currentPage,
        ];
    }
}
