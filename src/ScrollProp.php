<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\Support\Header;

/**
 * Marks a paginated prop the client scrolls through: each page it loads is merged into the
 * value it already holds, at the wrapper key of the paginated data.
 *
 * The page object lists the prop's position under `scrollProps` (page name, previous, next and
 * current page, and whether the client reset it). Where that position comes from is the metadata
 * given at construction: a `ProvidesScrollMetadata`, a callable that builds one from the resolved
 * value, or, when none is given, the pager the model's `paginate()` stored under the `default`
 * group, read after the value resolves.
 *
 * The merge direction follows the client's `X-Inertia-Infinite-Scroll-Merge-Intent` header: the
 * resolver applies it through `configureMergeIntent()` on its own copy. The prop is immutable: each
 * modifier returns a new instance and leaves the original as it was. The value is resolved once
 * per instance, so a copy made after resolving keeps that value.
 */
final class ScrollProp implements Deferrable, Mergeable
{
    use MergesProps;
    use ResolvesCallables;

    /**
     * Held untyped: PHP has no property type for "a callable or a provider".
     *
     * @var callable(mixed): ProvidesScrollMetadata|ProvidesScrollMetadata|null
     */
    private readonly mixed $metadata;

    private bool $deferred      = false;
    private ?string $deferGroup = null;
    private MergeProp $merging;
    private mixed $resolved  = null;
    private bool $isResolved = false;

    /**
     * @param mixed                                                               $value    The paginated data, or a closure or invokable object
     *                                                                                      that produces it; called only when the prop is sent.
     * @param string                                                              $wrapper  The key of the paginated items inside the value.
     * @param callable(mixed): ProvidesScrollMetadata|ProvidesScrollMetadata|null $metadata The pagination position, or a callable that builds
     *                                                                                      it from the resolved value; the shared pager when `null`.
     */
    public function __construct(
        private readonly mixed $value,
        private readonly string $wrapper = 'data',
        callable|ProvidesScrollMetadata|null $metadata = null,
    ) {
        $this->metadata = $metadata;
        $this->merging  = new MergeProp([]);
    }

    /**
     * Applies the client's merge intent: `prepend` merges the wrapper at the front of the value
     * the client holds, anything else at the end.
     */
    public function configureMergeIntent(IncomingRequest $request): static
    {
        return $request->getHeaderLine(Header::INFINITE_SCROLL_MERGE_INTENT) === 'prepend'
            ? $this->prepend($this->wrapper)
            : $this->append($this->wrapper);
    }

    /**
     * The pagination position, as `scrollProps` lists it. Resolves the value first when the
     * position depends on it.
     *
     * @return array{pageName: string, previousPage: int|string|null, nextPage: int|string|null, currentPage: int|string|null}
     */
    public function metadata(): array
    {
        $provider = $this->metadataProvider();

        return [
            'pageName'     => $provider->getPageName(),
            'previousPage' => $provider->getPreviousPage(),
            'nextPage'     => $provider->getNextPage(),
            'currentPage'  => $provider->getCurrentPage(),
        ];
    }

    /**
     * Resolves the value once; later calls, and copies made afterwards, return the same result.
     */
    public function __invoke(): mixed
    {
        if (! $this->isResolved) {
            $this->resolved   = $this->resolveCallable($this->value);
            $this->isResolved = true;
        }

        return $this->resolved;
    }

    /**
     * Leaves the prop out of the first load and lists it under `deferredProps` in the group.
     */
    public function defer(?string $group = null): static
    {
        $copy             = clone $this;
        $copy->deferred   = true;
        $copy->deferGroup = $group;

        return $copy;
    }

    public function shouldDefer(): bool
    {
        return $this->deferred;
    }

    public function group(): string
    {
        return $this->deferGroup ?? 'default';
    }

    protected function mergeState(): MergeProp
    {
        return $this->merging;
    }

    protected function mergesFlag(): bool
    {
        return true;
    }

    protected function withMergeState(MergeProp $merging, ?bool $merges = null): static
    {
        $copy          = clone $this;
        $copy->merging = $merging;

        return $copy;
    }

    private function metadataProvider(): ProvidesScrollMetadata
    {
        if ($this->metadata instanceof ProvidesScrollMetadata) {
            return $this->metadata;
        }

        $value = $this();

        if ($this->metadata === null) {
            return ScrollMetadata::fromPager(service('pager'));
        }

        return ($this->metadata)($value);
    }
}
