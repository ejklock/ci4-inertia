<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use Closure;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Config\Services;
use InvalidArgumentException;

/**
 * A static shortcut for `service('inertia')`: every method forwards to the shared
 * `ResponseFactory` on each call, so a replaced or reset service is always the one reached.
 * It holds no state and cannot be instantiated.
 */
final class Inertia
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $props Per-render props, each a scalar, array, `null`,
     *                                    or `ResolvableProp`.
     *
     * @throws InvalidArgumentException When a prop value is none of those shapes.
     *
     * @see ResponseFactory::render()
     */
    public static function render(string $component, array $props = []): InertiaResponse
    {
        return self::factory()->render($component, $props);
    }

    /**
     * @see ResponseFactory::encryptHistory()
     */
    public static function encryptHistory(bool $encrypt = true): void
    {
        self::factory()->encryptHistory($encrypt);
    }

    /**
     * @see ResponseFactory::clearHistory()
     */
    public static function clearHistory(): void
    {
        self::factory()->clearHistory();
    }

    /**
     * @param callable $callback Produces the prop's value; invoked only when a partial reload
     *                           names the prop.
     * @param string   $group    The batch the client loads this prop in.
     *
     * @throws InvalidArgumentException When the group is empty or whitespace only.
     *
     * @see ResponseFactory::defer()
     */
    public static function defer(callable $callback, string $group = 'default'): DeferProp
    {
        return self::factory()->defer($callback, $group);
    }

    /**
     * @param array<array-key, mixed>|Closure $value An array sent as-is, or a closure invoked
     *                                               only when the prop is resolved.
     *
     * @see ResponseFactory::merge()
     */
    public static function merge(array|Closure $value): MergeProp
    {
        return self::factory()->merge($value);
    }

    /**
     * @param array<array-key, mixed>|Closure $value An array sent as-is, or a closure invoked
     *                                               only when the prop is resolved.
     *
     * @see ResponseFactory::deepMerge()
     */
    public static function deepMerge(array|Closure $value): MergeProp
    {
        return self::factory()->deepMerge($value);
    }

    /**
     * @param array<string, mixed>|string $key   A single prop name, or a map of prop
     *                                           names to values to share at once.
     * @param mixed                       $value The value to share when `$key` is a
     *                                           single prop name; ignored otherwise.
     *
     * @throws InvalidArgumentException When a shared value is not scalar, array, `null`,
     *                                  or `ResolvableProp`.
     *
     * @see ResponseFactory::share()
     */
    public static function share(array|string $key, mixed $value = null): void
    {
        self::factory()->share($key, $value);
    }

    /**
     * @see ResponseFactory::version()
     */
    public static function version(callable|string $version): void
    {
        self::factory()->version($version);
    }

    /**
     * @see ResponseFactory::location()
     */
    public static function location(string $url): ResponseInterface
    {
        return self::factory()->location($url);
    }

    private static function factory(): ResponseFactory
    {
        return Services::inertia();
    }
}
