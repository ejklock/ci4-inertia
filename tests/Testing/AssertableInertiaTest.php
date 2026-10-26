<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Testing;

use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use Ejklock\Ci4Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Verifies `AssertableInertia`: page-level assertions, dot-path presence and value
 * assertions, strict comparison, and fluent chaining that stops at the first failure.
 *
 * @internal
 */
final class AssertableInertiaTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return [
            'component' => 'Users/Index',
            'props'     => [
                'title'   => 'Users',
                'count'   => 2,
                'nothing' => null,
                'users'   => [['name' => 'Ada'], ['name' => 'Linus']],
            ],
            'url'     => '/users',
            'version' => 'v1',
        ];
    }

    private function assertable(): AssertableInertia
    {
        return AssertableInertia::fromPage($this->page());
    }

    /**
     * @param Closure(AssertableInertia): AssertableInertia $assertions
     */
    private function assertPasses(Closure $assertions): void
    {
        $page = $this->assertable();

        $this->assertSame($page, $assertions($page));
    }

    private function expectFailure(string ...$messageParts): void
    {
        $this->expectException(AssertionFailedError::class);

        foreach ($messageParts as $part) {
            $this->expectExceptionMessage($part);
        }
    }

    #[DataProvider('provideFromPageRejectsPageMissingRequiredKey')]
    public function testFromPageRejectsPageMissingRequiredKey(string $key): void
    {
        $page = $this->page();
        unset($page[$key]);

        $this->expectFailure($key);

        AssertableInertia::fromPage($page);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideFromPageRejectsPageMissingRequiredKey(): iterable
    {
        yield 'component' => ['component'];

        yield 'props' => ['props'];

        yield 'url' => ['url'];

        yield 'version' => ['version'];
    }

    public function testPageExposesUnknownKeysUntouched(): void
    {
        $page                  = $this->page();
        $page['deferredProps'] = ['default' => ['stats']];

        $this->assertSame($page, AssertableInertia::fromPage($page)->page());
    }

    public function testComponentUrlAndVersionPassWhenEqual(): void
    {
        $assertable = $this->assertable();

        $this->assertSame($assertable, $assertable->component('Users/Index')->url('/users')->version('v1'));
    }

    public function testComponentFailureNamesBothComponents(): void
    {
        $this->expectFailure('Posts/Index', 'Users/Index');

        $this->assertable()->component('Posts/Index');
    }

    public function testUrlFailureNamesBothUrls(): void
    {
        $this->expectFailure('/posts', '/users');

        $this->assertable()->url('/posts');
    }

    public function testVersionFailureNamesBothVersions(): void
    {
        $this->expectFailure('v2', 'v1');

        $this->assertable()->version('v2');
    }

    public function testHasPassesOnPresentKeyAndNestedListIndex(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->has('users')->has('users.0.name'));
    }

    public function testHasPassesWhenValueIsNull(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->has('nothing'));
    }

    public function testHasFailsOnAbsentPath(): void
    {
        $this->expectFailure('users.5');

        $this->assertable()->has('users.5');
    }

    public function testHasFailsOnPathThroughScalar(): void
    {
        $this->expectFailure('title.length');

        $this->assertable()->has('title.length');
    }

    public function testHasWithCountPassesOnMatchingList(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->has('users', 2));
    }

    public function testHasWithCountFailureNamesPathExpectedAndActual(): void
    {
        $this->expectFailure('users', '3', '2');

        $this->assertable()->has('users', 3);
    }

    public function testMissingPassesOnAbsentPathAndPathThroughScalar(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->missing('users.5')->missing('title.length'));
    }

    public function testMissingFailsOnPresentKey(): void
    {
        $this->expectFailure('title');

        $this->assertable()->missing('title');
    }

    public function testMissingFailsOnPresentNullValue(): void
    {
        $this->expectFailure('nothing');

        $this->assertable()->missing('nothing');
    }

    public function testWherePassesOnStrictlyEqualValue(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->where('title', 'Users')->where('count', 2)->where('nothing', null));
    }

    public function testWhereFailsOnLooselyEqualValueOfAnotherType(): void
    {
        $this->expectFailure('count');

        $this->assertable()->where('count', '2');
    }

    public function testWhereFailsOnAbsentPath(): void
    {
        $this->expectFailure('users.9.name');

        $this->assertable()->where('users.9.name', 'Ada');
    }

    public function testWhereClosureReceivesValueAndPassesOnTrue(): void
    {
        $received = null;

        $this->assertable()->where('title', static function (mixed $value) use (&$received): bool {
            $received = $value;

            return true;
        });

        $this->assertSame('Users', $received);
    }

    public function testWhereClosureFailsOnFalseNamingPath(): void
    {
        $this->expectFailure('title');

        $this->assertable()->where('title', static fn (mixed $value): bool => false);
    }

    public function testWhereClosureFailsOnTruthyNonTrueReturn(): void
    {
        $this->expectFailure('title');

        $this->assertable()->where('title', static fn (mixed $value): int => 1);
    }

    public function testCountPassesOnMatchingArray(): void
    {
        $this->assertPasses(static fn (AssertableInertia $page): AssertableInertia => $page->count('users', 2));
    }

    public function testCountFailureNamesExpectedAndActual(): void
    {
        $this->expectFailure('users', '5', '2');

        $this->assertable()->count('users', 5);
    }

    public function testCountFailsOnNonArrayValue(): void
    {
        $this->expectFailure('title');

        $this->assertable()->count('title', 1);
    }

    public function testCountFailsOnAbsentPath(): void
    {
        $this->expectFailure('absent');

        $this->assertable()->count('absent', 0);
    }

    public function testPropReturnsValueAtPath(): void
    {
        $this->assertSame('Linus', $this->assertable()->prop('users.1.name'));
        $this->assertNull($this->assertable()->prop('nothing'));
    }

    public function testPropFailsOnAbsentPath(): void
    {
        $this->expectFailure('users.5');

        $this->assertable()->prop('users.5');
    }

    public function testChainStopsAtFirstFailure(): void
    {
        $reached = false;

        try {
            $this->assertable()
                ->component('Posts/Index')
                ->where('title', static function () use (&$reached): bool {
                    $reached = true;

                    return true;
                });
            $this->fail('the chain should have failed on the component');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Posts/Index', $e->getMessage());
        }

        $this->assertFalse($reached);
    }
}
