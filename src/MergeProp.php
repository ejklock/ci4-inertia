<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use ReflectionClass;

/**
 * Marks a prop value the client combines with the value it already holds instead of replacing it.
 *
 * The prop is sent like any other, and its path is listed under the page object's `mergeProps`
 * (appended), `prependProps` (prepended) or `deepMergeProps` (deep-merged); the fields set
 * with `matchOn()` are listed under `matchPropsOn` so the client dedupes array items on them.
 * The fluent methods keep Laravel's names and effects, but the prop is immutable: each call
 * returns a new instance and leaves the original as it was. It can also be marked once, so the
 * client keeps its copy after the first load.
 */
final readonly class MergeProp implements Mergeable, Onceable
{
    use ResolvesOnce;

    private OnceState $once;
    private bool $deepMerge;

    /**
     * @var list<string>
     */
    private array $matchOn;

    private bool $append;

    /**
     * @var list<string>
     */
    private array $appendsAtPaths;

    /**
     * @var list<string>
     */
    private array $prependsAtPaths;

    /**
     * @param mixed $value The value to send; one that is a callable object is called when the
     *                     prop is resolved and never before.
     */
    public function __construct(private mixed $value)
    {
        [
            'deepMerge'       => $this->deepMerge,
            'matchOn'         => $this->matchOn,
            'append'          => $this->append,
            'appendsAtPaths'  => $this->appendsAtPaths,
            'prependsAtPaths' => $this->prependsAtPaths,
        ]           = self::defaults();
        $this->once = new OnceState();
    }

    /**
     * Resolves the value: a callable object is called, anything else is returned as it is.
     */
    public function __invoke(): mixed
    {
        return is_object($this->value) && is_callable($this->value) ? ($this->value)() : $this->value;
    }

    /**
     * Marks the prop for merging. A `MergeProp` is merged from construction, so this returns an
     * equal new instance.
     */
    public function merge(): static
    {
        return $this->with();
    }

    /**
     * Marks the prop for deep merging.
     */
    public function deepMerge(): static
    {
        return $this->with(deepMerge: true);
    }

    /**
     * Sets the item fields the client dedupes array items on, replacing any set earlier.
     *
     * @param list<string>|string $matchOn
     */
    public function matchOn(array|string $matchOn): static
    {
        return $this->with(matchOn: is_array($matchOn) ? $matchOn : [$matchOn]);
    }

    public function shouldMerge(): bool
    {
        return true;
    }

    public function shouldDeepMerge(): bool
    {
        return $this->deepMerge;
    }

    /**
     * @return list<string>
     */
    public function matchesOn(): array
    {
        return $this->matchOn;
    }

    public function appendsAtRoot(): bool
    {
        return $this->append && $this->mergesAtRoot();
    }

    public function prependsAtRoot(): bool
    {
        return ! $this->append && $this->mergesAtRoot();
    }

    /**
     * Appends the value to the client's existing one, optionally only at a path and with a
     * field to match on there.
     *
     * @param array<array-key, string>|bool|string $path `true` or `false` sets the root direction; a
     *                                                   string names a path; an array lists paths, a
     *                                                   string key pairing a path with its match field.
     */
    public function append(array|bool|string $path = true, ?string $matchOn = null): static
    {
        return $this->withDirection(true, $path, $matchOn);
    }

    /**
     * Prepends the value to the client's existing one, optionally only at a path and with a
     * field to match on there.
     *
     * @param array<array-key, string>|bool|string $path `true` or `false` sets the root direction; a
     *                                                   string names a path; an array lists paths, a
     *                                                   string key pairing a path with its match field.
     */
    public function prepend(array|bool|string $path = true, ?string $matchOn = null): static
    {
        return $this->withDirection(false, $path, $matchOn);
    }

    /**
     * @return list<string>
     */
    public function appendsAtPaths(): array
    {
        return $this->appendsAtPaths;
    }

    /**
     * @return list<string>
     */
    public function prependsAtPaths(): array
    {
        return $this->prependsAtPaths;
    }

    /**
     * The state of a prop that has not been shaped yet: merged, appending, with no paths.
     *
     * @return array{deepMerge: bool, matchOn: list<string>, append: bool, appendsAtPaths: list<string>, prependsAtPaths: list<string>}
     */
    private static function defaults(): array
    {
        return [
            'deepMerge'       => false,
            'matchOn'         => [],
            'append'          => true,
            'appendsAtPaths'  => [],
            'prependsAtPaths' => [],
        ];
    }

    private function mergesAtRoot(): bool
    {
        return $this->appendsAtPaths === [] && $this->prependsAtPaths === [];
    }

    /**
     * @param array<array-key, string>|bool|string $path
     */
    private function withDirection(bool $appending, array|bool|string $path, ?string $matchOn): static
    {
        if (is_array($path)) {
            $next = $this;

            foreach ($path as $key => $value) {
                $next = is_numeric($key)
                    ? $next->withDirection($appending, $value, null)
                    : $next->withDirection($appending, $key, $value);
            }

            return $next;
        }

        if (is_bool($path)) {
            return $this->with(append: $appending ? $path : ! $path);
        }

        $next = $appending
            ? $this->with(appendsAtPaths: [...$this->appendsAtPaths, $path])
            : $this->with(prependsAtPaths: [...$this->prependsAtPaths, $path]);

        // Truthiness, as in Laravel: the string "0" names no field.
        return in_array($matchOn, [null, '', '0'], true)
            ? $next
            : $next->matchOn([...$next->matchOn, $path . '.' . $matchOn]);
    }

    /**
     * Builds the next immutable instance. The constructor takes only the value, so the copy is
     * made without it and its properties are initialised here, once, from inside the class.
     *
     * @psalm-suppress InaccessibleProperty The copy is a fresh instance whose readonly properties are still uninitialised.
     *
     * @param list<string>|null $matchOn
     * @param list<string>|null $appendsAtPaths
     * @param list<string>|null $prependsAtPaths
     */
    private function with(
        ?bool $deepMerge = null,
        ?array $matchOn = null,
        ?bool $append = null,
        ?array $appendsAtPaths = null,
        ?array $prependsAtPaths = null,
        ?OnceState $once = null,
    ): static {
        $copy = (new ReflectionClass($this))->newInstanceWithoutConstructor();

        // This is the one sanctioned copy site; PHPStan only knows constructor writes.
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->value = $this->value;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->deepMerge = $deepMerge ?? $this->deepMerge;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->matchOn = $matchOn ?? $this->matchOn;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->append = $append ?? $this->append;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->appendsAtPaths = $appendsAtPaths ?? $this->appendsAtPaths;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->prependsAtPaths = $prependsAtPaths ?? $this->prependsAtPaths;
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $copy->once = $once ?? $this->once;

        return $copy;
    }

    private function withOnce(OnceState $state): static
    {
        return $this->with(once: $state);
    }
}
