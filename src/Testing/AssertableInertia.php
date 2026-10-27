<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Testing;

use Closure;
use Ejklock\Ci4Inertia\Support\Header;
use PHPUnit\Framework\Assert;

/**
 * Fluent assertions over a decoded Inertia page object: component, url and version, plus
 * dot-path assertions over props (`users.0.name`, a numeric segment indexes a list).
 *
 * A path names a literal key when one exists (`form.saved`), else it is walked segment by segment,
 * both with `array_key_exists`, so a prop whose value is `null` counts as present.
 * Keys of the page beyond the required four are kept and readable through `page()`.
 *
 * A page built by `InertiaTestTrait` also carries a request handle, so `reload()`,
 * `reloadOnly()`, `reloadExcept()` and `loadDeferredProps()` can follow the page with a partial
 * reload. A page built by `fromPage()` alone has none and fails those assertions.
 */
final readonly class AssertableInertia
{
    private const REQUIRED_KEYS = ['component', 'props', 'url', 'version'];

    /**
     * @param array<string, mixed>                                                $page
     * @param (Closure(string, array<string, string>): array<string, mixed>)|null $reloader requests a url with the given
     *                                                                                      headers and returns the page it answered with
     */
    private function __construct(private array $page, private ?Closure $reloader)
    {
    }

    /**
     * @param array<string, mixed> $page
     */
    public static function fromPage(array $page): self
    {
        return self::fromPageWithReloader($page, null);
    }

    /**
     * Builds the assertable with the handle `reload()` requests the page through; `InertiaTestTrait`
     * is its only caller. Without a handle, `reload()` fails.
     *
     * @internal
     *
     * @param array<string, mixed>                                                $page
     * @param (Closure(string, array<string, string>): array<string, mixed>)|null $reloader requests a url with the given
     *                                                                                      headers and returns the page it answered with
     */
    public static function fromPageWithReloader(array $page, ?Closure $reloader): self
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $page)) {
                Assert::fail(sprintf('The Inertia page is missing the required key [%s].', $key));
            }
        }

        if (! is_array($page['props'])) {
            Assert::fail('The Inertia page key [props] is not an array.');
        }

        return new self($page, $reloader);
    }

    /**
     * @return array<string, mixed>
     */
    public function page(): array
    {
        return $this->page;
    }

    public function prop(string $path): mixed
    {
        [$found, $value] = $this->resolve($path);

        if (! $found) {
            Assert::fail(sprintf('The Inertia prop [%s] is missing.', $path));
        }

        return $value;
    }

    public function component(string $expected): static
    {
        $this->assertPageKey('component', $expected);

        return $this;
    }

    public function url(string $expected): static
    {
        $this->assertPageKey('url', $expected);

        return $this;
    }

    public function version(string $expected): static
    {
        $this->assertPageKey('version', $expected);

        return $this;
    }

    public function has(string $path, ?int $count = null): static
    {
        $value = $this->prop($path);

        if ($count !== null) {
            $this->assertCount($path, $value, $count);
        }

        return $this;
    }

    public function missing(string $path): static
    {
        [$found] = $this->resolve($path);

        if ($found) {
            Assert::fail(sprintf('The Inertia prop [%s] is present but was expected to be missing.', $path));
        }

        return $this;
    }

    /**
     * Asserts every path is present.
     */
    public function hasAll(array|string ...$paths): static
    {
        foreach ($this->flatten($paths) as $path) {
            $this->prop($path);
        }

        return $this;
    }

    /**
     * Asserts every path is absent.
     */
    public function missingAll(array|string ...$paths): static
    {
        foreach ($this->flatten($paths) as $path) {
            $this->missing($path);
        }

        return $this;
    }

    /**
     * Loads the page's deferred props with one partial reload and hands the reloaded page to
     * `$callback`. A closure alone loads every deferred group; a group name or a list of them
     * loads those. Fails when the chosen groups hold no key, rather than reloading the whole page.
     *
     * @param array<int, string>|Closure|string $groupsOrCallback
     */
    public function loadDeferredProps(array|Closure|string $groupsOrCallback, ?Closure $callback = null): static
    {
        $callback ??= $groupsOrCallback instanceof Closure ? $groupsOrCallback : null;
        $deferred = $this->page['deferredProps'] ?? [];
        /** @var array<string, list<string>> $deferred the page object's group name to keys map */
        $deferred = is_array($deferred) ? $deferred : [];
        $groups   = $groupsOrCallback instanceof Closure ? array_keys($deferred) : (array) $groupsOrCallback;

        $keys = [];

        foreach ($groups as $group) {
            $keys = [...$keys, ...$deferred[$group] ?? []];
        }

        if ($keys === []) {
            Assert::fail(sprintf('The Inertia page has no deferred props to load for the group(s) [%s].', implode(', ', $groups)));
        }

        return $this->reloadOnly($keys, $callback);
    }

    /**
     * Requests the page again with the version header (and the partial headers when `$only` or
     * `$except` is given), asserts the reloaded page has the same component, url and version, and
     * hands it to `$callback`. Returns this page, not the reloaded one.
     *
     * @param array<int, string>|string|null $only
     * @param array<int, string>|string|null $except
     */
    public function reload(?Closure $callback = null, array|string|null $only = null, array|string|null $except = null): static
    {
        if (! $this->reloader instanceof Closure) {
            Assert::fail('Reloading an Inertia page needs a test case that uses both FeatureTestTrait and InertiaTestTrait, and a page read through assertInertia().');
        }

        $only   = is_array($only) ? implode(',', $only) : $only;
        $except = is_array($except) ? implode(',', $except) : $except;

        $component = (string) $this->page['component'];
        $url       = (string) $this->page['url'];
        $version   = (string) $this->page['version'];

        $headers = [Header::VERSION => $version];

        if ($only !== null && $only !== '') {
            $headers[Header::PARTIAL_COMPONENT] = $component;
            $headers[Header::PARTIAL_ONLY]      = $only;
        }

        if ($except !== null && $except !== '') {
            $headers[Header::PARTIAL_COMPONENT] = $component;
            $headers[Header::PARTIAL_EXCEPT]    = $except;
        }

        $reloaded = self::fromPageWithReloader(($this->reloader)($url, $headers), $this->reloader);

        $reloaded->component($component);
        $reloaded->url($url);
        $reloaded->version($version);

        if ($callback instanceof Closure) {
            $callback($reloaded);
        }

        return $this;
    }

    /**
     * Reloads the page asking for only these props and asserts each one came back.
     *
     * @param array<int, string>|string $only
     */
    public function reloadOnly(array|string $only, ?Closure $callback = null): static
    {
        return $this->reload(static function (self $reloaded) use ($only, $callback): void {
            $reloaded->hasAll(...self::split($only));

            if ($callback instanceof Closure) {
                $callback($reloaded);
            }
        }, only: $only);
    }

    /**
     * Reloads the page asking for everything except these props and asserts each one is gone.
     *
     * @param array<int, string>|string $except
     */
    public function reloadExcept(array|string $except, ?Closure $callback = null): static
    {
        return $this->reload(static function (self $reloaded) use ($except, $callback): void {
            $reloaded->missingAll(...self::split($except));

            if ($callback instanceof Closure) {
                $callback($reloaded);
            }
        }, except: $except);
    }

    public function where(string $path, mixed $expected): static
    {
        $actual = $this->prop($path);

        if ($expected instanceof Closure) {
            Assert::assertTrue($expected($actual), sprintf('The Inertia prop [%s] did not satisfy the given closure.', $path));

            return $this;
        }

        Assert::assertSame($expected, $actual, sprintf('Unexpected value for the Inertia prop [%s].', $path));

        return $this;
    }

    public function count(string $path, int $expected): static
    {
        $this->assertCount($path, $this->prop($path), $expected);

        return $this;
    }

    /**
     * @param array<int, string>|string $paths a comma separated list or a list of paths
     *
     * @return list<string>
     */
    private static function split(array|string $paths): array
    {
        return is_array($paths) ? $paths : explode(',', $paths);
    }

    /**
     * @param array<int, array<int, string>|string> $paths
     *
     * @return list<string>
     */
    private function flatten(array $paths): array
    {
        return array_merge(...array_map(static fn (array|string $path): array => (array) $path, $paths));
    }

    private function assertPageKey(string $key, string $expected): void
    {
        $actual = $this->page[$key];

        if ($actual !== $expected) {
            Assert::fail(sprintf('Unexpected Inertia %s: expected [%s], got [%s].', $key, $expected, is_scalar($actual) ? (string) $actual : get_debug_type($actual)));
        }
    }

    private function assertCount(string $path, mixed $value, int $expected): void
    {
        if (! is_array($value)) {
            Assert::fail(sprintf('The Inertia prop [%s] is not an array, so it cannot be counted.', $path));
        }

        if (count($value) !== $expected) {
            Assert::fail(sprintf('Unexpected item count for the Inertia prop [%s]: expected %d, got %d.', $path, $expected, count($value)));
        }
    }

    /**
     * Asserts the page's flash data holds the key (a dot path reaches a nested value) and, when
     * an expected value is given, holds it strictly. A page without `flash` has empty flash data.
     */
    public function hasFlash(string $key, mixed $expected = null): static
    {
        if (func_num_args() > 1) {
            self::assertFlashHas($this->flashData(), $key, $expected);
        } else {
            self::assertFlashHas($this->flashData(), $key);
        }

        return $this;
    }

    /**
     * Asserts the page's flash data does not hold the key.
     */
    public function missingFlash(string $key): static
    {
        self::assertFlashMissing($this->flashData(), $key);

        return $this;
    }

    /**
     * Asserts flash data holds the key and, when an expected value is given, holds it strictly.
     * Shared with the test trait, which reads the flash data from the session.
     *
     * @param array<array-key, mixed> $flash
     */
    public static function assertFlashHas(array $flash, string $key, mixed $expected = null): void
    {
        [$found, $actual] = self::walk($flash, $key);

        if (! $found) {
            Assert::fail(sprintf('Inertia Flash Data is missing key [%s].', $key));
        }

        if (func_num_args() > 2) {
            Assert::assertSame($expected, $actual, sprintf('Inertia Flash Data [%s] does not match expected value.', $key));
        }
    }

    /**
     * Asserts flash data does not hold the key.
     *
     * @param array<array-key, mixed> $flash
     */
    public static function assertFlashMissing(array $flash, string $key): void
    {
        if (self::walk($flash, $key)[0]) {
            Assert::fail(sprintf('Inertia Flash Data has unexpected key [%s].', $key));
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function flashData(): array
    {
        $flash = $this->page['flash'] ?? [];

        return is_array($flash) ? $flash : [];
    }

    /**
     * @return array{0: bool, 1: mixed} whether the path exists, and its value when it does
     */
    private function resolve(string $path): array
    {
        return self::walk($this->page['props'], $path);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array{0: bool, 1: mixed} whether the path exists in `$data`, and its value when it does
     */
    private static function walk(array $data, string $path): array
    {
        if (array_key_exists($path, $data)) {
            return [true, $data[$path]];
        }

        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }
}
