<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use Ejklock\Ci4Inertia\Support\Header;
use Ejklock\Ci4Inertia\Support\StrictPropValue;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

/**
 * Resolves the props of one render into the values to send and the page metadata, the way
 * inertia-laravel does: it walks the props tree, calls closures and invokable objects only for
 * the paths the request reaches, filters partial reloads by dot path, and collects the deferred
 * and merge metadata with path-qualified keys. An instance is single-use: it accumulates the
 * metadata of the one `resolve()` call.
 */
final class PropsResolver
{
    private readonly bool $isPartial;

    /**
     * @var list<string>|null
     */
    private readonly ?array $only;

    /**
     * @var list<string>|null
     */
    private readonly ?array $except;

    /**
     * @var list<string>
     */
    private readonly array $resetProps;

    /**
     * @var list<string>
     */
    private array $mergeProps = [];

    /**
     * @var list<string>
     */
    private array $prependProps = [];

    /**
     * @var list<string>
     */
    private array $deepMergeProps = [];

    /**
     * @var list<string>
     */
    private array $matchPropsOn = [];

    /**
     * @var array<string, list<string>>
     */
    private array $deferredProps = [];

    /**
     * @var array<string, array{prop: string, expiresAt: int|null}>
     */
    private array $onceProps = [];

    /**
     * @var array<string, array{pageName: string, previousPage: int|string|null, nextPage: int|string|null, currentPage: int|string|null, reset: bool}>
     */
    private array $scrollProps = [];

    /**
     * Paths of rescuing props whose resolution threw, in resolution order.
     *
     * @var list<string>
     */
    private array $rescuedProps = [];

    /**
     * @var list<string>
     */
    private readonly array $loadedOnceProps;

    private readonly bool $isInertia;

    /**
     * @var list<string>
     */
    private array $sharedPropKeys = [];

    /**
     * @param IncomingRequest $request   The current request; its headers drive partial reloads
     *                                   and it is handed to property providers.
     * @param string          $component The component being rendered; a partial reload counts
     *                                   only when it names this one.
     */
    public function __construct(private readonly IncomingRequest $request, private readonly string $component)
    {
        $this->isPartial  = $request->getHeaderLine(Header::PARTIAL_COMPONENT) === $component;
        $this->only       = $this->parseHeader($request, Header::PARTIAL_ONLY);
        $this->except     = $this->parseHeader($request, Header::PARTIAL_EXCEPT);
        $this->resetProps = $this->parseHeader($request, Header::RESET) ?? [];

        $this->loadedOnceProps = $this->parseHeader($request, Header::EXCEPT_ONCE_PROPS) ?? [];
        $this->isInertia       = $request->getHeaderLine(Header::INERTIA) !== '';
    }

    /**
     * Resolves the props and collects their metadata. The shared props are merged under the page
     * props, the page props winning, and their top-level keys are collected for the page's
     * `sharedProps` unless `Config\Inertia::$exposeSharedPropKeys` is off.
     *
     * @param array<array-key, mixed> $shared The shared props, as registered.
     * @param array<array-key, mixed> $props  The declared props, keyed by name.
     *
     * @return array{0: array<array-key, mixed>, 1: array<string, mixed>} The props to send and
     *                                                                    the non-empty metadata
     *                                                                    entries of the page object.
     */
    public function resolve(array $shared, array $props): array
    {
        $props = array_replace($this->resolveSharedProps($shared), $props);

        return [$this->resolveProps($this->unpackDotProps($props)), $this->buildMetadata()];
    }

    /**
     * Writes a value at a dot path the way Laravel's `Arr::set` does: a missing or non-array
     * segment is replaced by an empty array on the way down.
     *
     * @param array<array-key, mixed> $array
     *
     * @internal Shared with `ResponseFactory::share()`; not a public contract.
     */
    public static function setByPath(array &$array, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $last     = array_pop($segments);
        $current  = &$array;

        foreach ($segments as $segment) {
            if (! isset($current[$segment]) || ! is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current[$last] = $value;
    }

    /**
     * Expands the property providers of the shared props and records which top-level keys
     * they carry.
     *
     * @param array<array-key, mixed> $shared
     *
     * @return array<string, mixed>
     */
    private function resolveSharedProps(array $shared): array
    {
        $resolved = $this->resolvePropertyProviders($shared);

        if (! config('Inertia')->exposeSharedPropKeys) {
            return $resolved;
        }

        foreach (array_keys($resolved) as $key) {
            $this->sharedPropKeys[] = explode('.', (string) $key)[0];
        }

        $this->sharedPropKeys = array_values(array_unique($this->sharedPropKeys));

        return $resolved;
    }

    /**
     * Replaces each `ProvidesInertiaProperties` at a numeric key by the keyed props it
     * provides, in place; the keys given after it win.
     *
     * @param array<array-key, mixed> $props
     *
     * @return array<string, mixed>
     */
    private function resolvePropertyProviders(array $props): array
    {
        $context = null;
        $result  = [];

        foreach ($props as $key => $value) {
            if (is_int($key) && $value instanceof ProvidesInertiaProperties) {
                $context ??= new RenderContext($this->component, $this->request);

                $result = [...$result, ...iterator_to_array($value->toInertiaProperties($context))];
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Unpacks the top-level dot-notation keys into nested arrays; a closure value, and a
     * closure standing at a segment the path crosses, is called first.
     *
     * @param array<array-key, mixed> $props
     *
     * @return array<array-key, mixed>
     */
    private function unpackDotProps(array $props): array
    {
        foreach ($props as $key => $value) {
            if (! is_string($key) || ! str_contains($key, '.')) {
                continue;
            }

            if ($value instanceof Closure) {
                $value = $value();
            }

            $this->callClosuresAlongPath($props, $key);
            self::setByPath($props, $key, $value);
            unset($props[$key]);
        }

        return $props;
    }

    /**
     * @param array<array-key, mixed> $props
     */
    private function callClosuresAlongPath(array &$props, string $dotKey): void
    {
        $segments = explode('.', $dotKey);
        array_pop($segments);

        $current = &$props;

        foreach ($segments as $segment) {
            if (! isset($current[$segment])) {
                return;
            }

            if ($current[$segment] instanceof Closure) {
                $current[$segment] = $current[$segment]();
            }

            if (! is_array($current[$segment])) {
                return;
            }

            $current = &$current[$segment];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(): array
    {
        $metadata = array_filter([
            'sharedProps'    => $this->sharedPropKeys,
            'mergeProps'     => $this->mergeProps,
            'prependProps'   => $this->prependProps,
            'deepMergeProps' => $this->deepMergeProps,
            'matchPropsOn'   => $this->matchPropsOn,
            'deferredProps'  => $this->deferredProps,
        ], static fn (array $value): bool => $value !== []);

        if (isset($metadata['deferredProps'])) {
            // An object, so integer-like group names stay a JSON map instead of collapsing into a list.
            $metadata['deferredProps'] = (object) $metadata['deferredProps'];
        }

        if ($this->rescuedProps !== []) {
            $metadata['rescuedProps'] = $this->rescuedProps;
        }

        if ($this->scrollProps !== []) {
            $metadata['scrollProps'] = $this->scrollProps;
        }

        if ($this->onceProps !== []) {
            // An object for the same reason as the deferred groups: a key like "7" must stay a map key.
            $metadata['onceProps'] = (object) $this->onceProps;
        }

        return $metadata;
    }

    /**
     * Recursively resolves the props tree, collecting metadata along the way.
     *
     * @param array<array-key, mixed> $props
     *
     * @return array<array-key, mixed>
     */
    private function resolveProps(array $props, string $prefix = '', bool $parentWasResolved = false): array
    {
        $props  = $this->resolvePropertyProviders($props);
        $result = [];

        foreach ($props as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $prop = $value;

            // On a partial reload only the paths the headers reach are kept. An `AlwaysProp` and
            // the children of an already resolved value bypass that filter.
            if (! $this->shouldIncludeInPartialResponse($prop, $path, $parentWasResolved)) {
                continue;
            }

            // On a full render the props the client loads later are dropped before they are
            // resolved, so their callbacks never run.
            if (! $this->isPartial && $this->excludeFromInitialResponse($prop, $path)) {
                continue;
            }

            $prop  = $this->withMergeIntent($prop);
            $value = $this->resolveValue($prop, $path, $props);

            if (in_array($path, $this->rescuedProps, true)) {
                continue;
            }

            // A closure may return a prop type; it is unwrapped one level so that type takes part
            // in the filtering and the metadata below.
            if ($value !== $prop && $this->isPropType($value)) {
                $prop = $value;

                if (! $this->isPartial && $this->excludeFromInitialResponse($prop, $path)) {
                    continue;
                }

                $prop  = $this->withMergeIntent($prop);
                $value = $this->resolveValue($prop, $path, $props, false);
            }

            $this->collectMetadata($prop, $path);

            // The children of a value that was not an array before resolving, such as a closure's
            // result, bypass the partial filter: the closure's key was already matched.
            $result[$key] = is_array($value)
                ? $this->resolveProps($value, $path, $parentWasResolved || ! is_array($prop))
                : $value;
        }

        return $result;
    }

    /**
     * A scroll prop merges in the direction the client asked for; the prop the caller gave stays
     * as it was, so the configured copy is the one the metadata is collected from.
     */
    private function withMergeIntent(mixed $prop): mixed
    {
        return $prop instanceof ScrollProp ? $prop->configureMergeIntent($this->request) : $prop;
    }

    private function shouldIncludeInPartialResponse(mixed $prop, string $path, bool $parentWasResolved): bool
    {
        if (! $this->isPartial || $prop instanceof AlwaysProp || $parentWasResolved) {
            return true;
        }

        return $this->pathMatchesPartialRequest($path);
    }

    /**
     * Bidirectional prefix matching: a path is kept when it, or an ancestor, is named by
     * `X-Inertia-Partial-Data`, or when it leads to a named path; it is dropped when it, or an
     * ancestor, is named by `X-Inertia-Partial-Except`.
     */
    private function pathMatchesPartialRequest(string $path): bool
    {
        if ($this->only !== null && ! $this->matchesPath($this->only, $path) && ! $this->leadsToOnly($path)) {
            return false;
        }

        return $this->except === null || ! $this->matchesPath($this->except, $path);
    }

    /**
     * Whether a prop is left out of a full render; the ones the client loads later still
     * contribute their metadata before they go.
     */
    private function excludeFromInitialResponse(mixed $prop, string $path): bool
    {
        if ($prop instanceof IgnoreFirstLoad) {
            return $this->excludeIgnoredProp($prop, $path);
        }

        if ($prop instanceof Deferrable && $prop->shouldDefer()) {
            return $this->excludeDeferredProp($prop, $path);
        }

        if ($this->isInertia && $this->wasAlreadyLoadedByClient($prop, $path)) {
            $this->collectOnceMetadata($path, $prop);

            return true;
        }

        return false;
    }

    /**
     * An `IgnoreFirstLoad` prop is always left out. A deferred one is listed for the client to
     * fetch unless the client already holds it as a once prop.
     */
    private function excludeIgnoredProp(IgnoreFirstLoad $prop, string $path): bool
    {
        if ($prop instanceof Deferrable && $prop->shouldDefer() && ! $this->wasAlreadyLoadedByClient($prop, $path)) {
            $this->deferredProps[$prop->group()][] = $path;
        }

        if ($prop instanceof Mergeable && $prop->shouldMerge()) {
            $this->collectMergeableMetadata($path, $prop);
        }

        $this->collectOnceMetadata($path, $prop);

        return true;
    }

    private function excludeDeferredProp(Deferrable $prop, string $path): bool
    {
        $this->deferredProps[$prop->group()][] = $path;

        if ($prop instanceof Mergeable && $prop->shouldMerge()) {
            $this->collectMergeableMetadata($path, $prop);
        }

        return true;
    }

    /**
     * Whether the client already holds a once prop: it names the prop's key, or its path when it
     * has none, and the prop is not forced to refresh.
     */
    private function wasAlreadyLoadedByClient(mixed $prop, string $path): bool
    {
        return $prop instanceof Onceable
            && $prop->shouldResolveOnce()
            && ! $prop->shouldBeRefreshed()
            && in_array($prop->getKey() ?? $path, $this->loadedOnceProps, true);
    }

    /**
     * Calls a callable object, converts a `ProvidesInertiaProperty` with its context, then turns
     * the result into plain data: a `JsonSerializable` becomes its `jsonSerialize()` value. A
     * string or array that merely names a function stays as it is.
     *
     * With `Config\Inertia::$strictProps` on, an object other than a `stdClass` data bag or a
     * backed enum is refused before `jsonSerialize()` runs, and a `stdClass` is refused when
     * anything inside it is. The refusal is thrown outside the rescue, so a rescuable prop never
     * hides it. A prop type that resolution produced, such as the one a closure returned, is not
     * refused as an object: as with the flag off, a `JsonSerializable` one becomes its
     * `jsonSerialize()` value once, which is checked with the same rule and is not serialized again, and any other is returned as
     * it is, because the caller unwraps it and resolves it again. A non-callable prop type the
     * caller gave as it is has no such step and is refused like any other object.
     *
     * @param array<array-key, mixed> $siblings The props at the same level.
     *
     * @throws InvalidArgumentException When strict props refuses the resolved value.
     */
    private function resolveValue(mixed $value, string $path, array $siblings, bool $mayYieldPropType = true): mixed
    {
        $shouldRescue = $value instanceof Rescuable && $value->shouldRescue();
        $given        = $value;
        $refusal      = null;
        $serialized   = false;

        try {
            if (is_object($value) && is_callable($value)) {
                $value = $value();
            }

            if ($value instanceof ProvidesInertiaProperty) {
                $value = $value->toInertiaProperty(new PropertyContext($path, $siblings, $this->request));
            }

            if ($mayYieldPropType && $value !== $given && $this->isPropType($value)) {
                if (! $value instanceof JsonSerializable) {
                    return $value;
                }

                $value      = $value->jsonSerialize();
                $serialized = true;
            }

            $refusal = $this->strictRefusal($value, $path);

            if ($refusal === null) {
                return $value instanceof JsonSerializable && ! $serialized ? $value->jsonSerialize() : $value;
            }
        } catch (Throwable $e) {
            if (! $shouldRescue) {
                throw $e;
            }

            $this->logRescued($e);
            $this->rescuedProps[] = $path;

            return null;
        }

        throw $refusal;
    }

    private function strictPropsOn(): bool
    {
        return (bool) config('Inertia')->strictProps;
    }

    private function strictRefusal(mixed $value, string $path): ?InvalidArgumentException
    {
        if (! is_object($value) || ! $this->strictPropsOn()) {
            return null;
        }

        return StrictPropValue::refusal($value, $path);
    }

    /**
     * Logs a rescued throwable in the shape the framework's exception handler uses, at `error`
     * because the request carries on.
     */
    private function logRescued(Throwable $e): void
    {
        log_message('error', $e::class . ": {message}\nin {exFile} on line {exLine}.\n{trace}", [
            'message' => $e->getMessage(),
            'exFile'  => clean_path($e->getFile()),
            'exLine'  => $e->getLine(),
            'trace'   => render_backtrace($e->getTrace()),
        ]);
    }

    private function isPropType(mixed $value): bool
    {
        return $value instanceof AlwaysProp
            || $value instanceof Deferrable
            || $value instanceof IgnoreFirstLoad
            || $value instanceof Mergeable
            || $value instanceof Onceable;
    }

    private function collectMetadata(mixed $prop, string $path): void
    {
        if ($prop instanceof Mergeable && $prop->shouldMerge()) {
            $this->collectMergeableMetadata($path, $prop);
        }

        if ($prop instanceof ScrollProp) {
            $this->collectScrollMetadata($path, $prop);
        }

        $this->collectOnceMetadata($path, $prop);
    }

    private function collectScrollMetadata(string $path, ScrollProp $prop): void
    {
        $this->scrollProps[$path] = [
            ...$prop->metadata(),
            'reset' => in_array($path, $this->resetProps, true),
        ];
    }

    /**
     * Lists a once prop under its key, or its path when it has none, unless a partial reload
     * does not reach the path.
     */
    private function collectOnceMetadata(string $path, mixed $prop): void
    {
        if (! $prop instanceof Onceable || ! $prop->shouldResolveOnce()) {
            return;
        }

        if ($this->isPartial && ! $this->isIncludedInPartialMetadata($path)) {
            return;
        }

        $this->onceProps[$prop->getKey() ?? $path] = ['prop' => $path, 'expiresAt' => $prop->expiresAt()];
    }

    private function collectMergeableMetadata(string $path, Mergeable $prop): void
    {
        if (in_array($path, $this->resetProps, true)) {
            return;
        }

        if ($this->isPartial && ! $this->isIncludedInPartialMetadata($path)) {
            return;
        }

        if ($prop->shouldDeepMerge()) {
            $this->deepMergeProps[] = $path;
        } elseif ($prop->appendsAtRoot()) {
            $this->mergeProps[] = $path;
        } elseif ($prop->prependsAtRoot()) {
            $this->prependProps[] = $path;
        } else {
            foreach ($prop->appendsAtPaths() as $appendPath) {
                $this->mergeProps[] = $path . '.' . $appendPath;
            }

            foreach ($prop->prependsAtPaths() as $prependPath) {
                $this->prependProps[] = $path . '.' . $prependPath;
            }
        }

        foreach ($prop->matchesOn() as $strategy) {
            $this->matchPropsOn[] = $path . '.' . $strategy;
        }
    }

    private function isIncludedInPartialMetadata(string $path): bool
    {
        if ($this->only !== null && ! $this->matchesPath($this->only, $path)) {
            return false;
        }

        return $this->except === null || ! $this->matchesPath($this->except, $path);
    }

    /**
     * Whether the path equals one of the filter paths or sits under one.
     *
     * @param list<string> $filterPaths
     */
    private function matchesPath(array $filterPaths, string $path): bool
    {
        foreach ($filterPaths as $filterPath) {
            if ($path === $filterPath || str_starts_with($path, $filterPath . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the path is an ancestor of one of the `X-Inertia-Partial-Data` paths.
     */
    private function leadsToOnly(string $path): bool
    {
        foreach ($this->only ?? [] as $onlyPath) {
            if (str_starts_with($onlyPath, $path . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a comma-separated header into its names without trimming them, dropping empty
     * items; an absent header, or one with no names, is no filter at all rather than an empty
     * one.
     *
     * @return list<string>|null
     */
    private function parseHeader(IncomingRequest $request, string $name): ?array
    {
        $names = array_values(array_filter(explode(',', $request->getHeaderLine($name))));

        return $names === [] ? null : $names;
    }
}
