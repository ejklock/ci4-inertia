<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Filters\InertiaFilter;
use InvalidArgumentException;
use Throwable;

/**
 * What an `Inertia::handleExceptionsUsing()` callback receives for an uncaught exception: the
 * exception, the request and the response CodeIgniter would fill, with a fluent way to answer
 * with an Inertia page instead. A callback that never calls `render()` leaves the response
 * untouched.
 */
final class ExceptionResponse
{
    private ?string $component = null;

    /**
     * @var array<array-key, mixed>
     */
    private array $props = [];

    private bool $withSharedData = false;
    private ?string $rootView    = null;

    /**
     * @var class-string<InertiaFilter>|null
     */
    private ?string $filterClass = null;

    /**
     * @param int $statusCode The status CodeIgniter's handler passed, already known to be valid.
     */
    public function __construct(
        public readonly Throwable $exception,
        public readonly IncomingRequest $request,
        public readonly ResponseInterface $response,
        private readonly int $statusCode,
    ) {
    }

    /**
     * Answers with the `$component` page carrying `$props`, rendered with the exception's status.
     *
     * @param array<array-key, mixed> $props
     */
    public function render(string $component, array $props = []): static
    {
        $this->component = $component;
        $this->props     = $props;

        return $this;
    }

    /**
     * Names the filter whose version, and shared props when asked, the page uses, instead of the
     * first `InertiaFilter` among the `Config\Filters` aliases.
     *
     * @param class-string $filterClass
     *
     * @throws InvalidArgumentException When the class is not an `InertiaFilter`.
     */
    public function usingFilter(string $filterClass): static
    {
        if (! is_a($filterClass, InertiaFilter::class, true)) {
            throw new InvalidArgumentException(sprintf('%s is not an %s.', $filterClass, InertiaFilter::class));
        }

        $this->filterClass = $filterClass;

        return $this;
    }

    /**
     * Adds the filter's `share()` props to the page, `errors` included. Props a `before` filter
     * already shared on the `inertia` service stay on the page either way.
     */
    public function withSharedData(): static
    {
        $this->withSharedData = true;

        return $this;
    }

    /**
     * Renders the HTML shell of the page with `$rootView` instead of the configured root view.
     */
    public function rootView(string $rootView): static
    {
        $this->rootView = $rootView;

        return $this;
    }

    /**
     * The HTTP status CodeIgniter's handler passed for the exception, `500` when it was not a
     * valid status.
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * The response to send: the given one untouched when `render()` was never called; otherwise
     * the page rendered into it with the exception's status and `Vary: X-Inertia`, since no
     * `after` filter runs for an uncaught exception.
     */
    public function toResponse(): ResponseInterface
    {
        if ($this->component === null) {
            return $this->response;
        }

        $inertia = service('inertia');
        $filter  = $this->resolveFilter();

        $filter?->prepareExceptionRender($this->request, $inertia, $this->withSharedData);

        if ($this->rootView !== null) {
            $inertia->setRootView($this->rootView);
        }

        $response = $inertia->render($this->component, $this->props)
            ->toResponse($this->request, $this->response)
            ->setStatusCode($this->statusCode);

        return InertiaFilter::applyVaryHeader($response);
    }

    /**
     * The filter named by `usingFilter()`, else the first alias of `Config\Filters` that is an
     * `InertiaFilter`, whether the alias holds one class or a list of them. It is built with
     * `new`, as CodeIgniter builds a filter.
     */
    private function resolveFilter(): ?InertiaFilter
    {
        $class = $this->filterClass ?? $this->filterClassFromAliases();

        return $class === null ? null : new $class();
    }

    /**
     * @return class-string<InertiaFilter>|null
     */
    private function filterClassFromAliases(): ?string
    {
        foreach (config('Filters')->aliases as $alias) {
            foreach ((array) $alias as $class) {
                if (is_a($class, InertiaFilter::class, true)) {
                    /** @var class-string<InertiaFilter> $filterClass */
                    $filterClass = $class;

                    return $filterClass;
                }
            }
        }

        return null;
    }
}
