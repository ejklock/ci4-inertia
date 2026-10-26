<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Testing;

use Closure;
use PHPUnit\Framework\Assert;

/**
 * Fluent assertions over a decoded Inertia page object: component, url and version, plus
 * dot-path assertions over props (`users.0.name`, a numeric segment indexes a list).
 *
 * Paths are walked with `array_key_exists`, so a prop whose value is `null` counts as
 * present. Keys of the page beyond the required four are kept and readable through `page()`.
 */
final readonly class AssertableInertia
{
    private const REQUIRED_KEYS = ['component', 'props', 'url', 'version'];

    /**
     * @param array<string, mixed> $page
     */
    private function __construct(private array $page)
    {
    }

    /**
     * @param array<string, mixed> $page
     */
    public static function fromPage(array $page): self
    {
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $page)) {
                Assert::fail(sprintf('The Inertia page is missing the required key [%s].', $key));
            }
        }

        if (! is_array($page['props'])) {
            Assert::fail('The Inertia page key [props] is not an array.');
        }

        return new self($page);
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
     * @return array{0: bool, 1: mixed} whether the path exists, and its value when it does
     */
    private function resolve(string $path): array
    {
        $current = $this->page['props'];

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }
}
