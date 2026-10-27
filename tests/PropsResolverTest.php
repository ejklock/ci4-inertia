<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App as AppConfig;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\Deferrable;
use Ejklock\Ci4Inertia\MergeProp;
use Ejklock\Ci4Inertia\OptionalProp;
use Ejklock\Ci4Inertia\ResponseFactory;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * Verifies how props resolve into the page object: closures and invokable objects as
 * props, partial reloads filtered by dot path, and the deferred and merge metadata with
 * path-qualified keys. Every case renders through `ResponseFactory::render()` with a real
 * request.
 *
 * @internal
 */
final class PropsResolverTest extends CIUnitTestCase
{
    private const COMPONENT = 'Users/Index';

    /**
     * @param array<string, mixed>  $props
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function renderPage(array $props, array $headers = []): array
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());

        foreach (['X-Inertia' => 'true', ...$headers] as $name => $value) {
            $request->setHeader($name, $value);
        }

        $factory  = new ResponseFactory($request, 'app', static fn (): string => 'v1');
        $response = $factory->render(self::COMPONENT, $props)->toResponse($request, service('response', null, false));

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function partial(array $headers): array
    {
        return ['X-Inertia-Partial-Component' => self::COMPONENT, ...$headers];
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return array<string, mixed>
     */
    private function metadata(array $page): array
    {
        return array_diff_key($page, array_flip(['component', 'props', 'url', 'version']));
    }

    /**
     * @return ArrayObject<int, string>
     */
    private function newCalls(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * Builds a callback that records its name in `$calls` each time it runs.
     *
     * @param ArrayObject<int, string> $calls
     */
    private function recording(ArrayObject $calls, string $name, mixed $value): Closure
    {
        return static function () use ($calls, $name, $value): mixed {
            $calls->append($name);

            return $value;
        };
    }

    public function testAClosurePropIsResolvedToItsReturnValueOnAFullRender(): void
    {
        $page = $this->renderPage(['count' => static fn (): int => 3]);

        $this->assertSame(['count' => 3], $page['props']);
    }

    public function testAnInvokableObjectPropIsResolvedToItsReturnValue(): void
    {
        $invokable = new class () {
            public function __invoke(): string
            {
                return 'from-invoke';
            }
        };

        $page = $this->renderPage(['label' => $invokable]);

        $this->assertSame(['label' => 'from-invoke'], $page['props']);
    }

    public function testAStringNamingAFunctionStaysAString(): void
    {
        $page = $this->renderPage(['label' => 'strtoupper']);

        $this->assertSame(['label' => 'strtoupper'], $page['props']);
    }

    public function testAClosurePropLeftOutOfAPartialReloadNeverRuns(): void
    {
        $calls = $this->newCalls();

        $page = $this->renderPage(
            ['kept' => 1, 'skipped' => $this->recording($calls, 'skipped', 2)],
            $this->partial(['X-Inertia-Partial-Data' => 'kept']),
        );

        $this->assertSame(['kept' => 1], $page['props']);
        $this->assertCount(0, $calls);
    }

    public function testAClosureReturningAnOptionalPropIsLeftOutOfAFullRenderAndItsCallbackNeverRuns(): void
    {
        $calls    = $this->newCalls();
        $callback = $this->recording($calls, 'inner', 9);

        $page = $this->renderPage([
            'a'     => 1,
            'extra' => static fn (): OptionalProp => new OptionalProp($callback),
        ]);

        $this->assertSame(['a' => 1], $page['props']);
        $this->assertCount(0, $calls);
        $this->assertSame([], $this->metadata($page));
    }

    public function testAClosureReturningADeferPropIsLeftOutOfAFullRenderAndStillListedAsDeferred(): void
    {
        $calls    = $this->newCalls();
        $callback = $this->recording($calls, 'inner', 9);

        $page = $this->renderPage([
            'a'     => 1,
            'later' => static fn (): DeferProp => new DeferProp($callback, 'sidebar'),
        ]);

        $this->assertSame(['a' => 1], $page['props']);
        $this->assertCount(0, $calls);
        $this->assertSame(['sidebar' => ['later']], $page['deferredProps']);
    }

    public function testAClosureReturningAnArrayIsSentWhole(): void
    {
        $report = static fn (): array => ['a' => 1, 'b' => 2];

        $named = $this->renderPage(['report' => $report], $this->partial(['X-Inertia-Partial-Data' => 'report']));
        $child = $this->renderPage(['report' => $report], $this->partial(['X-Inertia-Partial-Data' => 'report.a']));

        $this->assertSame(['report' => ['a' => 1, 'b' => 2]], $named['props']);
        $this->assertSame(['report' => ['a' => 1, 'b' => 2]], $child['props']);
    }

    public function testAJsonSerializableObjectIsSentAsItsJsonSerializeValue(): void
    {
        $entity = new class () implements JsonSerializable {
            /**
             * @return array<string, int>
             */
            public function jsonSerialize(): array
            {
                return ['id' => 5];
            }
        };

        $page = $this->renderPage(['user' => $entity, 'lazy' => static fn (): JsonSerializable => $entity]);

        $this->assertSame(['user' => ['id' => 5], 'lazy' => ['id' => 5]], $page['props']);
    }

    public function testAPlainObjectPropIsEncodedAsJson(): void
    {
        $object      = new stdClass();
        $object->say = 'hi';

        $page = $this->renderPage(['thing' => $object]);

        $this->assertSame(['thing' => ['say' => 'hi']], $page['props']);
    }

    public function testAnExceptionThrownInsideAClosurePropPropagates(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('boom');

        $this->renderPage(['bad' => static fn (): never => throw new Exception('boom')]);
    }

    /**
     * @param ArrayObject<int, string> $calls
     *
     * @return array<string, mixed>
     */
    private function authProps(ArrayObject $calls): array
    {
        return [
            'auth' => [
                'user'        => $this->recording($calls, 'user', 'ada'),
                'permissions' => $this->recording($calls, 'permissions', ['edit']),
                'token'       => new AlwaysProp($this->recording($calls, 'token', 't0k')),
            ],
            'flash' => 'x',
        ];
    }

    public function testPartialDataNamingANestedPathSendsOnlyThatPath(): void
    {
        $calls = $this->newCalls();

        $page = $this->renderPage($this->authProps($calls), $this->partial(['X-Inertia-Partial-Data' => 'auth.user']));

        $this->assertSame(['auth' => ['user' => 'ada', 'token' => 't0k']], $page['props']);
        $this->assertSame(['user', 'token'], $calls->getArrayCopy());
    }

    public function testPartialDataNamingAParentSendsTheWholeSubtree(): void
    {
        $page = $this->renderPage($this->authProps($this->newCalls()), $this->partial(['X-Inertia-Partial-Data' => 'auth']));

        $this->assertSame(['auth' => ['user' => 'ada', 'permissions' => ['edit'], 'token' => 't0k']], $page['props']);
    }

    public function testPartialExceptNamingANestedPathDropsOnlyThatPath(): void
    {
        $calls = $this->newCalls();

        $page = $this->renderPage($this->authProps($calls), $this->partial(['X-Inertia-Partial-Except' => 'auth.permissions']));

        $this->assertSame(['auth' => ['user' => 'ada', 'token' => 't0k'], 'flash' => 'x'], $page['props']);
        $this->assertNotContains('permissions', $calls->getArrayCopy());
    }

    public function testAPropWhosePathIsOnlyACharacterPrefixOfAnAncestorIsNotKept(): void
    {
        $page = $this->renderPage(
            ['aut' => 1, 'auth' => ['user' => 'ada'], 'flash' => 'x'],
            $this->partial(['X-Inertia-Partial-Data' => 'auth.user']),
        );

        $this->assertSame(['auth' => ['user' => 'ada']], $page['props']);
    }

    public function testPropsAfterAFilteredOutPropAreStillResolved(): void
    {
        $partial = $this->renderPage(['skip' => 1, 'kept' => 2, 'also' => 3], $this->partial(['X-Inertia-Partial-Data' => 'kept,also']));
        $full    = $this->renderPage(['later' => new DeferProp(static fn (): int => 1), 'a' => 1, 'opt' => new OptionalProp(static fn (): int => 2), 'b' => 2]);

        $this->assertSame(['kept' => 2, 'also' => 3], $partial['props']);
        $this->assertSame(['a' => 1, 'b' => 2], $full['props']);
    }

    public function testAClosureReturningAnAlwaysPropIsUnwrappedToItsValue(): void
    {
        $page = $this->renderPage(['token' => static fn (): AlwaysProp => new AlwaysProp(static fn (): int => 7)]);

        $this->assertSame(['token' => 7], $page['props']);
    }

    public function testPropsAfterAClosureThatReturnsAnOptionalPropAreStillResolved(): void
    {
        $page = $this->renderPage([
            'extra' => static fn (): OptionalProp => new OptionalProp(static fn (): int => 1),
            'a'     => 1,
        ]);

        $this->assertSame(['a' => 1], $page['props']);
    }

    public function testADeferrableThatIsNotIgnoredOnFirstLoadIsLeftOutOnlyWhileItDefers(): void
    {
        $make = static fn (bool $defers): Deferrable => new class ($defers) implements Deferrable {
            public function __construct(private readonly bool $defers)
            {
            }

            public function shouldDefer(): bool
            {
                return $this->defers;
            }

            public function group(): string
            {
                return 'custom';
            }

            public function __invoke(): int
            {
                return 5;
            }
        };

        $deferring = $this->renderPage(['x' => $make(true), 'a' => 1]);
        $loaded    = $this->renderPage(['x' => $make(false), 'a' => 1]);

        $this->assertSame(['a' => 1], $deferring['props']);
        $this->assertSame(['custom' => ['x']], $deferring['deferredProps']);
        $this->assertSame(['x' => 5, 'a' => 1], $loaded['props']);
        $this->assertArrayNotHasKey('deferredProps', $loaded);
    }

    public function testOnlyACallableObjectIsCalledWhenAPropResolves(): void
    {
        $plain = new stdClass();

        $this->assertSame($plain, (new AlwaysProp($plain))());
        $this->assertSame('strtoupper', (new AlwaysProp('strtoupper'))());
        $this->assertSame($plain, (new MergeProp($plain))());
        $this->assertSame('strtoupper', (new MergeProp('strtoupper'))());
        $this->assertSame('phpversion', (new OptionalProp('phpversion'))());
        $this->assertSame('phpversion', (new DeferProp('phpversion'))());
    }

    public function testOptionalAndDeferPropsCallClosuresAndInvokableObjectsButNotArrayCallables(): void
    {
        $invokable = new class () {
            public function __invoke(): string
            {
                return 'invoked';
            }

            public function method(): string
            {
                return 'method';
            }
        };

        $this->assertSame('closure', (new OptionalProp(static fn (): string => 'closure'))());
        $this->assertSame('closure', (new DeferProp(static fn (): string => 'closure'))());
        $this->assertSame('invoked', (new OptionalProp($invokable))());
        $this->assertSame('invoked', (new DeferProp($invokable))());
        // An array callable is deliberately not a first-class callable: it must stay an array.
        $method        = 'method';
        $arrayCallable = [$invokable, $method];

        $this->assertSame($arrayCallable, (new OptionalProp($arrayCallable))());
        $this->assertSame($arrayCallable, (new DeferProp($arrayCallable))());
    }

    public function testAPartialDataPrefixOnlyMatchesUpToADot(): void
    {
        $page = $this->renderPage($this->authProps($this->newCalls()), $this->partial(['X-Inertia-Partial-Data' => 'aut']));

        $this->assertArrayNotHasKey('auth', $page['props']);
        $this->assertSame([], $page['props']);
    }

    public function testAnAlwaysPropNestedInsideAPartialPathIsSentAlongside(): void
    {
        $page = $this->renderPage($this->authProps($this->newCalls()), $this->partial(['X-Inertia-Partial-Data' => 'auth.user']));

        $this->assertSame('t0k', $page['props']['auth']['token']);
        $this->assertArrayNotHasKey('flash', $page['props']);
    }

    public function testAPartialDataItemThatIsOnlyASpaceMatchesNothingWhileTheOthersApply(): void
    {
        $page = $this->renderPage($this->authProps($this->newCalls()), $this->partial(['X-Inertia-Partial-Data' => 'auth.user, ,flash']));

        $this->assertSame(['auth' => ['user' => 'ada', 'token' => 't0k'], 'flash' => 'x'], $page['props']);
    }

    public function testAPartialDataItemWithALeadingSpaceIsNotTrimmedAndSoMatchesNothing(): void
    {
        $page = $this->renderPage($this->authProps($this->newCalls()), $this->partial(['X-Inertia-Partial-Data' => 'auth.user, flash']));

        $this->assertArrayNotHasKey('flash', $page['props']);
        $this->assertSame('ada', $page['props']['auth']['user']);
    }

    public function testAPartialDataHeaderWithNoNamesIsNoFilterRatherThanAnEmptyOne(): void
    {
        $page = $this->renderPage(['a' => 1, 'b' => 2], $this->partial(['X-Inertia-Partial-Data' => ',']));

        $this->assertSame(['a' => 1, 'b' => 2], $page['props']);
    }

    public function testAPartialReloadForAnotherComponentIsAFullRender(): void
    {
        $page = $this->renderPage(
            ['a' => 1, 'b' => 2, 'later' => new DeferProp(static fn (): int => 3)],
            ['X-Inertia-Partial-Component' => 'Other/Page', 'X-Inertia-Partial-Data' => 'a'],
        );

        $this->assertSame(['a' => 1, 'b' => 2], $page['props']);
        $this->assertSame(['default' => ['later']], $page['deferredProps']);
    }

    public function testDeferredPropsAreLeftOutAndListedByGroupInDeclarationOrder(): void
    {
        $page = $this->renderPage([
            'a'       => 1,
            'first'   => new DeferProp(static fn (): int => 1),
            'sidebar' => new DeferProp(static fn (): int => 2, 'sidebar'),
            'second'  => new DeferProp(static fn (): int => 3),
        ]);

        $this->assertSame(['a' => 1], $page['props']);
        $this->assertSame(['default' => ['first', 'second'], 'sidebar' => ['sidebar']], $page['deferredProps']);
    }

    public function testANestedDeferPropIsListedUnderItsDotPath(): void
    {
        $calls = $this->newCalls();

        $page = $this->renderPage(['settings' => ['name' => 'x', 'heavy' => new DeferProp($this->recording($calls, 'heavy', 1))]]);

        $this->assertSame(['settings' => ['name' => 'x']], $page['props']);
        $this->assertSame(['default' => ['settings.heavy']], $page['deferredProps']);
        $this->assertCount(0, $calls);
    }

    public function testAnOptionalPropIsAbsentAndListedNowhere(): void
    {
        $calls = $this->newCalls();

        $page = $this->renderPage(['a' => 1, 'opt' => new OptionalProp($this->recording($calls, 'opt', 2))]);

        $this->assertSame(['a' => 1], $page['props']);
        $this->assertSame([], $this->metadata($page));
        $this->assertCount(0, $calls);
    }

    public function testAPartialReloadNamingADeferredKeySendsItWithoutTheDeferredList(): void
    {
        $page = $this->renderPage(
            ['a' => 1, 'later' => new DeferProp(static fn (): int => 7)],
            $this->partial(['X-Inertia-Partial-Data' => 'later']),
        );

        $this->assertSame(['later' => 7], $page['props']);
        $this->assertArrayNotHasKey('deferredProps', $page);
    }

    public function testIntegerLikeGroupNamesStayAJsonObject(): void
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());
        $request->setHeader('X-Inertia', 'true');

        $factory = new ResponseFactory($request, 'app', static fn (): string => 'v1');
        $body    = (string) preg_replace('/\s+/', '', (string) $factory
            ->render(self::COMPONENT, ['a' => new DeferProp(static fn (): int => 1, '0')])
            ->toResponse($request, service('response', null, false))
            ->getBody());

        $this->assertStringContainsString('"deferredProps":{"0":["a"]}', $body);
    }

    public function testTheFactoryDefersIntoTheDefaultGroupOrTheGivenOne(): void
    {
        $config  = config(AppConfig::class);
        $factory = new ResponseFactory(new IncomingRequest($config, new SiteURI($config, 'x'), null, new UserAgent()), 'app', static fn (): string => 'v1');

        $page = $this->renderPage([
            'plain'   => $factory->defer(static fn (): int => 1),
            'sidebar' => $factory->defer(static fn (): int => 2, 'sidebar'),
        ]);

        $this->assertSame(['default' => ['plain'], 'sidebar' => ['sidebar']], $page['deferredProps']);
    }

    /**
     * A blank group stays an error here although Laravel accepts one; keeping the guard
     * means a typo cannot silently create a group the client never loads.
     */
    #[DataProvider('provideABlankDeferGroupThrows')]
    public function testABlankDeferGroupThrows(string $group): void
    {
        $config  = config(AppConfig::class);
        $factory = new ResponseFactory(new IncomingRequest($config, new SiteURI($config, 'x'), null, new UserAgent()), 'app', static fn (): string => 'v1');

        $this->expectException(InvalidArgumentException::class);

        $factory->defer(static fn (): int => 1, $group);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideABlankDeferGroupThrows(): iterable
    {
        yield 'empty' => [''];

        yield 'whitespace' => ['  '];
    }

    public function testMergeMetadataUsesTheAppendPrependAndDeepShapes(): void
    {
        $page = $this->renderPage([
            'appended'  => new MergeProp([1]),
            'prepended' => (new MergeProp([2]))->prepend(),
            'deep'      => (new MergeProp(['k' => 1]))->deepMerge(),
        ]);

        $this->assertSame(['appended'], $page['mergeProps']);
        $this->assertSame(['prepended'], $page['prependProps']);
        $this->assertSame(['deep'], $page['deepMergeProps']);
    }

    /**
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('provideMergeMetadataAtPaths')]
    public function testMergeMetadataAtPaths(MergeProp $prop, array $expected): void
    {
        $page = $this->renderPage(['key' => $prop]);

        $this->assertSame($expected, $this->metadata($page));
    }

    /**
     * @return iterable<string, array{MergeProp, array<string, list<string>>}>
     */
    public static function provideMergeMetadataAtPaths(): iterable
    {
        yield 'append at a path leaves the root out' => [
            (new MergeProp(['data' => []]))->append('data'),
            ['mergeProps' => ['key.data']],
        ];

        yield 'prepend at a path' => [
            (new MergeProp(['data' => []]))->prepend('data'),
            ['prependProps' => ['key.data']],
        ];

        yield 'append at a path with a match field' => [
            (new MergeProp(['data' => []]))->append('data', 'id'),
            ['mergeProps' => ['key.data'], 'matchPropsOn' => ['key.data.id']],
        ];

        yield 'append from an array of paths and match fields' => [
            (new MergeProp(['data' => [], 'meta' => []]))->append(['data' => 'id', 'meta']),
            ['mergeProps' => ['key.data', 'key.meta'], 'matchPropsOn' => ['key.data.id']],
        ];

        yield 'match on a field' => [
            (new MergeProp([1]))->matchOn('id'),
            ['mergeProps' => ['key'], 'matchPropsOn' => ['key.id']],
        ];

        yield 'a second match on replaces the first' => [
            (new MergeProp([1]))->matchOn('id')->matchOn(['uuid']),
            ['mergeProps' => ['key'], 'matchPropsOn' => ['key.uuid']],
        ];

        yield 'deep merge is listed only as deep' => [
            (new MergeProp([1]))->deepMerge(),
            ['deepMergeProps' => ['key']],
        ];
    }

    public function testAKeyNamedInResetIsSentButLeftOutOfEveryMergeList(): void
    {
        $page = $this->renderPage(
            ['key' => (new MergeProp([1]))->matchOn('id'), 'other' => new MergeProp([2])],
            ['X-Inertia-Reset' => 'key'],
        );

        $this->assertSame([1], $page['props']['key']);
        $this->assertSame(['mergeProps' => ['other']], $this->metadata($page));
    }

    public function testAPartialReloadExceptingAMergeKeyLeavesItOutOfEveryList(): void
    {
        $page = $this->renderPage(
            ['key' => (new MergeProp([1]))->matchOn('id'), 'a' => 1],
            $this->partial(['X-Inertia-Partial-Except' => 'key']),
        );

        $this->assertSame(['a' => 1], $page['props']);
        $this->assertSame([], $this->metadata($page));
    }

    public function testAMergePropNestedInAnArrayIsListedUnderItsDotPath(): void
    {
        $page = $this->renderPage(['feed' => ['items' => new MergeProp([1]), 'title' => 'x']]);

        $this->assertSame(['feed' => ['items' => [1], 'title' => 'x']], $page['props']);
        $this->assertSame(['feed.items'], $page['mergeProps']);
    }

    public function testADeferredMergePropIsOutOfTheFirstLoadAndListedAsDeferredAndMerged(): void
    {
        $config  = config(AppConfig::class);
        $factory = new ResponseFactory(new IncomingRequest($config, new SiteURI($config, 'x'), null, new UserAgent()), 'app', static fn (): string => 'v1');

        $page = $this->renderPage(['a' => 1, 'feed' => $factory->defer(static fn (): array => [1], 'g')->merge()]);

        $this->assertSame(['a' => 1], $page['props']);
        $this->assertSame(['g' => ['feed']], $page['deferredProps']);
        $this->assertSame(['feed'], $page['mergeProps']);
    }
}
