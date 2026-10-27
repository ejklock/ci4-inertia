<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use InvalidArgumentException;
use ReflectionClass;

/**
 * Marks a prop value the client loads in a follow-up request after the first render.
 *
 * A full render leaves a `DeferProp` out of `props` without invoking its callback and lists its
 * path under the page object's `deferredProps`, grouped by name; the client then issues one
 * partial reload per group naming that group's paths. A partial reload resolves and sends the
 * prop when it reaches it.
 *
 * A deferred prop is not mergeable until `merge()` or `deepMerge()` is called; only those two
 * turn merging on. `append()`, `prepend()` and `matchOn()` only shape the merge and leave a
 * prop that is not merging out of every merge list. A merging prop is also listed in the
 * matching merge list, with the same meaning as for `MergeProp`. The fluent methods keep
 * Laravel's names and effects, but the prop is immutable: each call returns a new instance. It can
 * also be marked once, so the client keeps its copy after loading it.
 *
 * A prop built with `rescue` set is left out of the response when its callback throws: the
 * throwable is logged, and the prop's path is listed under the page object's `rescuedProps`.
 */
final readonly class DeferProp implements Deferrable, IgnoreFirstLoad, Mergeable, Onceable, Rescuable
{
    use MergesProps;
    use ResolvesCallables;
    use ResolvesOnce;

    private OnceState $once;
    private mixed $callback;
    private string $group;
    private MergeProp $merging;
    private bool $merges;

    /**
     * @param callable    $callback Produces the prop's real value when the prop is invoked. Only a
     *                              callable object (a closure or an invokable object) is called; a
     *                              string or array callable is returned as it is, as Laravel does.
     *                              Never called at construction time.
     * @param string|null $group    The name the client batches this prop's follow-up request
     *                              under; props sharing a group load together. `default` when
     *                              `null`.
     * @param bool        $rescue   Whether a throw from the callback is logged and the prop left
     *                              out instead of failing the request.
     *
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     */
    public function __construct(callable $callback, ?string $group = null, private bool $rescue = false)
    {
        $this->callback = $callback;
        $this->group    = self::checkedGroup($group);
        $this->merging  = new MergeProp([]);
        $this->merges   = false;
        $this->once     = new OnceState();
    }

    /**
     * Resolves the deferred value: a closure or invokable object is called for it, while a
     * string or array callable comes back unchanged.
     */
    public function __invoke(): mixed
    {
        return $this->resolveCallable($this->callback);
    }

    /**
     * Sets the group the client batches this prop's follow-up request under.
     *
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     */
    public function defer(?string $group = null): static
    {
        return $this->with(group: self::checkedGroup($group));
    }

    public function shouldDefer(): bool
    {
        return true;
    }

    public function shouldRescue(): bool
    {
        return $this->rescue;
    }

    public function group(): string
    {
        return $this->group;
    }

    protected function mergeState(): MergeProp
    {
        return $this->merging;
    }

    protected function mergesFlag(): bool
    {
        return $this->merges;
    }

    protected function withMergeState(MergeProp $merging, ?bool $merges = null): static
    {
        return $this->with(merging: $merging, merges: $merges);
    }

    /**
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     */
    private static function checkedGroup(?string $group): string
    {
        $group ??= 'default';

        if (trim($group) === '') {
            throw new InvalidArgumentException('A deferred prop group must not be empty or whitespace only.');
        }

        return $group;
    }

    /**
     * Builds the next immutable instance. The constructor takes only Laravel's parameters, so the
     * copy is made without it and its properties are initialised here, once, from inside the class.
     *
     * @psalm-suppress InaccessibleProperty The copy is a fresh instance whose readonly properties are still uninitialised.
     */
    private function with(?string $group = null, ?MergeProp $merging = null, ?bool $merges = null, ?OnceState $once = null): static
    {
        $copy = (new ReflectionClass($this))->newInstanceWithoutConstructor();

        // This is the one sanctioned copy site; PHPStan only knows constructor writes.
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->callback = $this->callback;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->group = $group ?? $this->group;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->merging = $merging ?? $this->merging;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->merges = $merges ?? $this->merges;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->once = $once ?? $this->once;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->rescue = $this->rescue;

        return $copy;
    }

    private function withOnce(OnceState $state): static
    {
        return $this->with(once: $state);
    }
}
