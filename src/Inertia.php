<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia;

use BackedEnum;
use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use Ejklock\Ci4Inertia\Config\Services;
use InvalidArgumentException;
use UnitEnum;

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
     * @param array<array-key, mixed>|ProvidesInertiaProperties $props Per-render props; a closure
     *                                                                 or invokable object is called
     *                                                                 only when the prop is sent. A
     *                                                                 `ProvidesInertiaProperties`
     *                                                                 stands for a list of that one.
     *
     * @throws InvalidArgumentException When the component is not a string once the transformer and
     *                                  the enum mapping have run, such as an int-backed enum.
     *
     * @see ResponseFactory::render()
     */
    public static function render(BackedEnum|string|UnitEnum $component, array|ProvidesInertiaProperties $props = []): InertiaResponse
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
     * @see ResponseFactory::preserveFragment()
     */
    public static function preserveFragment(): void
    {
        self::factory()->preserveFragment();
    }

    /**
     * @param array<string, mixed>|BackedEnum|string|UnitEnum $key
     *
     * @see ResponseFactory::flash()
     */
    public static function flash(array|BackedEnum|string|UnitEnum $key, mixed $value = null): ResponseFactory
    {
        return self::factory()->flash($key, $value);
    }

    /**
     * @return array<string, mixed>
     *
     * @see ResponseFactory::getFlashed()
     */
    public static function getFlashed(): array
    {
        return self::factory()->getFlashed();
    }

    /**
     * @return array<string, mixed>
     *
     * @see ResponseFactory::pullFlashed()
     */
    public static function pullFlashed(): array
    {
        return self::factory()->pullFlashed();
    }

    /**
     * @param array<string, string> $headers
     *
     * @see ResponseFactory::back()
     */
    public static function back(int $status = 302, array $headers = [], false|string $fallback = false): RedirectResponse
    {
        return self::factory()->back($status, $headers, $fallback);
    }

    /**
     * @param callable $callback Produces the prop's value; invoked only when a partial reload
     *                           reaches the prop.
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
     * @param mixed $value The value sent as-is, or a closure or invokable object called only
     *                     when the prop is sent.
     *
     * @see ResponseFactory::merge()
     */
    public static function merge(mixed $value): MergeProp
    {
        return self::factory()->merge($value);
    }

    /**
     * @param mixed $value The value sent as-is, or a closure or invokable object called only
     *                     when the prop is sent.
     *
     * @see ResponseFactory::deepMerge()
     */
    public static function deepMerge(mixed $value): MergeProp
    {
        return self::factory()->deepMerge($value);
    }

    /**
     * @param array<array-key, mixed>|ProvidesInertiaProperties|string $key   A single prop name,
     *                                                                        where a dot path nests
     *                                                                        the value; a map of
     *                                                                        prop names to values;
     *                                                                        or a provider of
     *                                                                        several props.
     * @param mixed                                                    $value The value to share
     *                                                                        when `$key` is a
     *                                                                        single prop name;
     *                                                                        ignored otherwise.
     *
     * @see ResponseFactory::share()
     */
    public static function share(array|ProvidesInertiaProperties|string $key, mixed $value = null): void
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
     * @see ResponseFactory::getVersion()
     */
    public static function getVersion(): string
    {
        return self::factory()->getVersion();
    }

    /**
     * @see ResponseFactory::getShared()
     */
    public static function getShared(?string $key = null, mixed $default = null): mixed
    {
        return self::factory()->getShared($key, $default);
    }

    /**
     * @see ResponseFactory::flushShared()
     */
    public static function flushShared(): void
    {
        self::factory()->flushShared();
    }

    /**
     * @see ResponseFactory::setRootView()
     */
    public static function setRootView(string $rootView): void
    {
        self::factory()->setRootView($rootView);
    }

    /**
     * @param (Closure(IncomingRequest): string)|null $urlResolver
     *
     * @see ResponseFactory::resolveUrlUsing()
     */
    public static function resolveUrlUsing(?Closure $urlResolver = null): void
    {
        self::factory()->resolveUrlUsing($urlResolver);
    }

    /**
     * @see ResponseFactory::transformComponentUsing()
     */
    public static function transformComponentUsing(?Closure $componentTransformer = null): void
    {
        self::factory()->transformComponentUsing($componentTransformer);
    }

    /**
     * @see ResponseFactory::optional()
     */
    public static function optional(callable $callback): OptionalProp
    {
        return self::factory()->optional($callback);
    }

    /**
     * @see ResponseFactory::once()
     */
    public static function once(callable $callback): OnceProp
    {
        return self::factory()->once($callback);
    }

    /**
     * @see ResponseFactory::shareOnce()
     */
    public static function shareOnce(string $key, callable $callback): OnceProp
    {
        return self::factory()->shareOnce($key, $callback);
    }

    /**
     * @see ResponseFactory::always()
     */
    public static function always(mixed $value): AlwaysProp
    {
        return self::factory()->always($value);
    }

    /**
     * @see ResponseFactory::location()
     */
    public static function location(RedirectResponse|string $url): ResponseInterface
    {
        return self::factory()->location($url);
    }

    private static function factory(): ResponseFactory
    {
        return Services::inertia();
    }
}
