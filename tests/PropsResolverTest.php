<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use ArrayObject;
use Closure;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\TestLogger;
use Config\App as AppConfig;
use DateInterval;
use DateTimeImmutable;
use Ejklock\Ci4Inertia\AlwaysProp;
use Ejklock\Ci4Inertia\DeferProp;
use Ejklock\Ci4Inertia\Deferrable;
use Ejklock\Ci4Inertia\Mergeable;
use Ejklock\Ci4Inertia\MergeProp;
use Ejklock\Ci4Inertia\OnceProp;
use Ejklock\Ci4Inertia\OptionalProp;
use Ejklock\Ci4Inertia\PropertyContext;
use Ejklock\Ci4Inertia\PropsResolver;
use Ejklock\Ci4Inertia\ProvidesInertiaProperties;
use Ejklock\Ci4Inertia\ProvidesInertiaProperty;
use Ejklock\Ci4Inertia\RenderContext;
use Ejklock\Ci4Inertia\Rescuable;
use Ejklock\Ci4Inertia\ResponseFactory;
use Exception;
use Generator;
use InvalidArgumentException;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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
     * @param array<array-key, mixed> $props
     * @param array<string, string>   $headers
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

    public function testOnceAndOptionalPropsResolveCallableObjectsAndKeepStringAndArrayCallables(): void
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
        $method        = 'method';
        $arrayCallable = [$invokable, $method];

        foreach ([OnceProp::class, OptionalProp::class] as $class) {
            $this->assertSame('closure', (new $class(static fn (): string => 'closure'))());
            $this->assertSame('invoked', (new $class($invokable))());
            $this->assertSame('strtoupper', (new $class('strtoupper'))());
            $this->assertSame($arrayCallable, (new $class($arrayCallable))());
        }
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

    /**
     * @param Closure(PropertyContext): mixed $convert
     */
    private function propertyProvider(Closure $convert): ProvidesInertiaProperty
    {
        return new class ($convert) implements ProvidesInertiaProperty {
            public function __construct(private readonly Closure $convert)
            {
            }

            public function toInertiaProperty(PropertyContext $prop): mixed
            {
                return ($this->convert)($prop);
            }
        };
    }

    /**
     * @param Closure(RenderContext): iterable<string, mixed> $provide
     */
    private function propertiesProvider(Closure $provide): ProvidesInertiaProperties
    {
        return new class ($provide) implements ProvidesInertiaProperties {
            public function __construct(private readonly Closure $provide)
            {
            }

            public function toInertiaProperties(RenderContext $context): iterable
            {
                return ($this->provide)($context);
            }
        };
    }

    public function testAPropertyProviderIsConvertedWithItsKeySiblingsAndRequest(): void
    {
        $seen = null;
        $page = $this->renderPage([
            'user' => $this->propertyProvider(static function (PropertyContext $prop) use (&$seen): string {
                $seen = $prop;

                return 'Ada';
            }),
            'role' => 'admin',
        ]);

        $this->assertSame(['user' => 'Ada', 'role' => 'admin'], $page['props']);
        $this->assertInstanceOf(PropertyContext::class, $seen);
        $this->assertSame('user', $seen->key);
        $this->assertSame(['user', 'role'], array_keys($seen->props));
        $this->assertSame('admin', $seen->props['role']);
        $this->assertSame('true', $seen->request->getHeaderLine('X-Inertia'));
    }

    public function testANestedPropertyProviderSeesItsDotPath(): void
    {
        $key  = null;
        $page = $this->renderPage(['auth' => ['user' => $this->propertyProvider(static function (PropertyContext $prop) use (&$key): string {
            $key = $prop->key;

            return 'Ada';
        })]]);

        $this->assertSame(['auth' => ['user' => 'Ada']], $page['props']);
        $this->assertSame('auth.user', $key);
    }

    public function testAPropertyProviderReturnedByAClosureIsConverted(): void
    {
        $provider = $this->propertyProvider(static fn (PropertyContext $prop): string => 'from ' . $prop->key);

        $page = $this->renderPage(['user' => static fn (): ProvidesInertiaProperty => $provider]);

        $this->assertSame(['user' => 'from user'], $page['props']);
    }

    public function testAPropertyProviderUnderAKeyAPartialReloadLeavesOutIsNeverConverted(): void
    {
        $calls = 0;
        $page  = $this->renderPage([
            'user' => $this->propertyProvider(static function () use (&$calls): string {
                $calls++;

                return 'Ada';
            }),
            'other' => 1,
        ], $this->partial(['X-Inertia-Partial-Data' => 'other']));

        $this->assertSame(['other' => 1], $page['props']);
        $this->assertSame(0, $calls);
    }

    public function testAPropertyProviderReturningAnArrayHasItsChildrenResolved(): void
    {
        $page = $this->renderPage(['user' => $this->propertyProvider(static fn (): array => [
            'name' => static fn (): string => 'Ada',
            'feed' => new MergeProp([1]),
        ])]);

        $this->assertSame(['user' => ['name' => 'Ada', 'feed' => [1]]], $page['props']);
        $this->assertSame(['user.feed'], $page['mergeProps']);
    }

    public function testAPropertiesProviderAtANumericKeyIsExpandedWithTheRenderContext(): void
    {
        $seen = null;
        $page = $this->renderPage([$this->propertiesProvider(static function (RenderContext $context) use (&$seen): array {
            $seen = $context;

            return ['a' => 1, 'b' => 2];
        })]);

        $this->assertSame(['a' => 1, 'b' => 2], $page['props']);
        $this->assertInstanceOf(RenderContext::class, $seen);
        $this->assertSame(self::COMPONENT, $seen->component);
        $this->assertSame('true', $seen->request->getHeaderLine('X-Inertia'));
    }

    public function testAPlainKeyGivenAfterAPropertiesProviderWins(): void
    {
        $provider = $this->propertiesProvider(static fn (): array => ['a' => 'provided', 'b' => 2]);

        $page = $this->renderPage([$provider, 'a' => 'plain']);

        $this->assertSame(['a' => 'plain', 'b' => 2], $page['props']);
    }

    public function testAPropertiesProviderNestedInAnArrayPropIsExpandedAtThatLevel(): void
    {
        $page = $this->renderPage(['auth' => [$this->propertiesProvider(static fn (): array => ['id' => 1]), 'name' => 'Ada']]);

        $this->assertSame(['auth' => ['id' => 1, 'name' => 'Ada']], $page['props']);
    }

    public function testAPropertiesProviderMayYieldFromAGenerator(): void
    {
        $page = $this->renderPage([$this->propertiesProvider(static function (): Generator {
            yield 'a' => 1;

            yield 'b' => 2;
        })]);

        $this->assertSame(['a' => 1, 'b' => 2], $page['props']);
    }

    public function testAPropertiesProviderAtAStringKeyIsAPlainValue(): void
    {
        $page = $this->renderPage(['extra' => $this->propertiesProvider(static fn (): array => ['a' => 1])]);

        $this->assertSame(['extra' => []], $page['props']);
    }

    public function testAPropertiesProviderAsTheWholePropsArgumentIsExpandedLikeAListOfOne(): void
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());
        $request->setHeader('X-Inertia', 'true');
        $factory = new ResponseFactory($request, 'app', static fn (): string => 'v1');

        $response = $factory
            ->render(self::COMPONENT, $this->propertiesProvider(static fn (): array => ['a' => 1]))
            ->toResponse($request, service('response', null, false));

        $page = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['a' => 1], $page['props']);
    }

    public function testEveryPropertiesProviderOfALevelReceivesTheSameContext(): void
    {
        $contexts = [];
        $record   = static function (RenderContext $context) use (&$contexts): array {
            $contexts[] = $context;

            return [];
        };

        $this->renderPage([$this->propertiesProvider($record), $this->propertiesProvider($record)]);

        $this->assertCount(2, $contexts);
        $this->assertSame($contexts[0], $contexts[1]);
    }

    public function testALeafClosureOfADotKeyIsNotCalledWhenTheDotKeyReplacesIt(): void
    {
        $calls = 0;
        $page  = $this->renderPage([
            'auth' => ['name' => static function () use (&$calls): string {
                $calls++;

                return 'old';
            }],
            'auth.name' => 'Ada',
        ]);

        $this->assertSame(['auth' => ['name' => 'Ada']], $page['props']);
        $this->assertSame(0, $calls);
    }

    public function testAClosureBelowAnArrayOnTheDotPathIsCalledAndMerged(): void
    {
        $page = $this->renderPage([
            'auth'              => ['profile' => static fn (): array => ['id' => 1]],
            'auth.profile.name' => 'Ada',
        ]);

        $this->assertSame(['auth' => ['profile' => ['id' => 1, 'name' => 'Ada']]], $page['props']);
    }

    public function testADotKeyIsUnpackedIntoNestedProps(): void
    {
        $page = $this->renderPage(['auth.user.name' => 'Ada']);

        $this->assertSame('{"auth":{"user":{"name":"Ada"}}}', json_encode($page['props']));
    }

    public function testADotKeyMergesIntoAnExistingArrayProp(): void
    {
        $page = $this->renderPage(['auth' => ['id' => 1], 'auth.name' => 'Ada']);

        $this->assertSame('{"auth":{"id":1,"name":"Ada"}}', json_encode($page['props']));
    }

    public function testAClosureParentOfADotKeyIsCalledOnceAndMerged(): void
    {
        $calls = 0;
        $page  = $this->renderPage([
            'auth' => static function () use (&$calls): array {
                $calls++;

                return ['id' => 1];
            },
            'auth.name' => 'Ada',
        ]);

        $this->assertSame(['auth' => ['id' => 1, 'name' => 'Ada']], $page['props']);
        $this->assertSame(1, $calls);
    }

    public function testAClosureValueOfADotKeyIsCalledAndSet(): void
    {
        $page = $this->renderPage(['auth.name' => static fn (): string => 'Ada']);

        $this->assertSame(['auth' => ['name' => 'Ada']], $page['props']);
    }

    public function testADotKeyThroughAScalarSiblingsStringOffsetReplacesTheScalar(): void
    {
        $page = $this->renderPage(['auth' => 'xyz', 'auth.0.name' => 'Ada']);

        $this->assertSame(['auth' => [['name' => 'Ada']]], $page['props']);
    }

    public function testADotKeyOverAScalarSiblingReplacesTheScalar(): void
    {
        $page = $this->renderPage(['auth' => 'x', 'auth.name' => 'Ada']);

        $this->assertSame(['auth' => ['name' => 'Ada']], $page['props']);
    }

    public function testANestedDotKeyStaysALiteralKey(): void
    {
        $page = $this->renderPage(['auth' => ['a.b' => 1]]);

        $this->assertSame(['auth' => ['a.b' => 1]], $page['props']);
    }

    public function testAPartialReloadOfADotPathSendsOnlyThatPath(): void
    {
        $page = $this->renderPage(
            ['auth.name' => 'Ada', 'auth.id' => 1, 'other' => 2],
            $this->partial(['X-Inertia-Partial-Data' => 'auth.name']),
        );

        $this->assertSame(['auth' => ['name' => 'Ada']], $page['props']);
    }

    public function testAOnceCallbackIsSentAndListedOnAFirstVisit(): void
    {
        $page = $this->renderPage(['plans' => new OnceProp(static fn (): string => 'v')]);

        $this->assertSame('v', $page['props']['plans']);
        $this->assertSame(['plans' => ['prop' => 'plans', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testAnInertiaVisitNamingAOnceKeyLeavesTheCallbackUncalledAndStillListsIt(): void
    {
        $calls = $this->newCalls();
        $page  = $this->renderPage(
            ['plans' => new OnceProp($this->recording($calls, 'plans', 'v'))],
            ['X-Inertia-Except-Once-Props' => 'plans'],
        );

        $this->assertArrayNotHasKey('plans', $page['props']);
        $this->assertCount(0, $calls);
        $this->assertSame(['plans' => ['prop' => 'plans', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testANonInertiaVisitNamingAOnceKeyStillSendsTheProp(): void
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());
        $request->setHeader('X-Inertia-Except-Once-Props', 'plans');

        [$props, $metadata] = (new PropsResolver($request, self::COMPONENT))
            ->resolve([], ['plans' => new OnceProp(static fn (): string => 'v')]);

        $this->assertSame(['plans' => 'v'], $props);
        $this->assertArrayHasKey('plans', (array) $metadata['onceProps']);
    }

    public function testACustomKeyIsListedWithThePathAndMustBeNamedToSkipTheProp(): void
    {
        $prop = (new OnceProp(static fn (): string => 'v'))->as('catalog');

        $byPath = $this->renderPage(['plans' => $prop], ['X-Inertia-Except-Once-Props' => 'plans']);
        $byKey  = $this->renderPage(['plans' => $prop], ['X-Inertia-Except-Once-Props' => 'catalog']);

        $this->assertSame('v', $byPath['props']['plans']);
        $this->assertSame(['catalog' => ['prop' => 'plans', 'expiresAt' => null]], $byPath['onceProps']);
        $this->assertArrayNotHasKey('plans', $byKey['props']);
        $this->assertSame(['catalog' => ['prop' => 'plans', 'expiresAt' => null]], $byKey['onceProps']);
    }

    public function testAFreshOnceIsSentEvenWhenTheClientNamesIt(): void
    {
        $page = $this->renderPage(
            ['plans' => (new OnceProp(static fn (): string => 'v'))->fresh()],
            ['X-Inertia-Except-Once-Props' => 'plans'],
        );

        $this->assertSame('v', $page['props']['plans']);
        $this->assertArrayHasKey('plans', $page['onceProps']);
    }

    public function testANestedOnceIsListedByItsPath(): void
    {
        $page = $this->renderPage(['auth' => ['plans' => new OnceProp(static fn (): string => 'v')]]);

        $this->assertSame(['auth.plans' => ['prop' => 'auth.plans', 'expiresAt' => null]], $page['onceProps']);

        $skipped = $this->renderPage(
            ['auth' => ['plans' => new OnceProp(static fn (): string => 'v'), 'name' => 'Ada']],
            ['X-Inertia-Except-Once-Props' => 'auth.plans'],
        );

        $this->assertSame(['name' => 'Ada'], $skipped['props']['auth']);
    }

    public function testAPartialReloadNamingAOnceSendsAndListsIt(): void
    {
        $page = $this->renderPage(
            ['plans' => new OnceProp(static fn (): string => 'v'), 'other' => 1],
            $this->partial(['X-Inertia-Partial-Data' => 'plans', 'X-Inertia-Except-Once-Props' => 'plans']),
        );

        $this->assertSame(['plans' => 'v'], $page['props']);
        $this->assertArrayHasKey('plans', $page['onceProps']);
    }

    public function testAPartialReloadNamingAnotherKeyDoesNotListTheOnce(): void
    {
        $page = $this->renderPage(
            ['plans' => new OnceProp(static fn (): string => 'v'), 'other' => 1],
            $this->partial(['X-Inertia-Partial-Data' => 'other']),
        );

        $this->assertArrayNotHasKey('onceProps', $page);
    }

    public function testAPartialReloadBelowAOncePropDoesNotListIt(): void
    {
        $props = ['auth' => new OnceProp(static fn (): array => ['name' => 'Ada', 'x' => 1])];

        $below = $this->renderPage($props, $this->partial(['X-Inertia-Partial-Data' => 'auth.name']));
        $named = $this->renderPage($props, $this->partial(['X-Inertia-Partial-Data' => 'auth']));

        $this->assertSame('Ada', $below['props']['auth']['name']);
        $this->assertArrayNotHasKey('onceProps', $below);
        $this->assertArrayHasKey('auth', $named['onceProps']);
    }

    public function testAnIntervalExpiryFollowsTheApplicationTimezoneAcrossADstChange(): void
    {
        $config              = config(AppConfig::class);
        $previous            = $config->appTimezone;
        $config->appTimezone = 'Europe/Berlin';

        try {
            Time::setTestNow('2030-03-30 12:00:00', 'Europe/Berlin');
            $now = Time::now()->getTimestamp();

            $expiresAt = (new MergeProp([1]))->once()->until(new DateInterval('P1D'))->expiresAt();

            $this->assertSame(($now + 23 * 3600) * 1000, $expiresAt);
        } finally {
            $config->appTimezone = $previous;
        }
    }

    public function testAPageWithoutOnceMarkedPropsHasNoOnceProps(): void
    {
        $this->assertArrayNotHasKey('onceProps', $this->renderPage(['a' => 1, 'b' => new MergeProp([1])]));
    }

    public function testOncePropsAreTheLastMetadataKey(): void
    {
        $page = $this->renderPage([
            'plans' => new OnceProp(static fn (): int => 1),
            'later' => (new DeferProp(static fn (): int => 1))->merge(),
        ]);

        $this->assertSame('onceProps', array_key_last($this->metadata($page)));
    }

    public function testAnIntegerLikeOnceKeyStaysAJsonObject(): void
    {
        $config  = config(AppConfig::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'users'), null, new UserAgent());
        $request->setHeader('X-Inertia', 'true');
        $factory = new ResponseFactory($request, 'app', static fn (): string => 'v1');
        $body    = (string) preg_replace('/\s+/', '', (string) $factory
            ->render(self::COMPONENT, ['a' => (new OnceProp(static fn (): int => 1))->as('0')])
            ->toResponse($request, service('response', null, false))
            ->getBody());

        $this->assertStringContainsString('"onceProps":{"0":{"prop":"a","expiresAt":null}}', $body);
    }

    public function testADeferredOnceIsExcludedAndListedAsDeferredAndOnce(): void
    {
        $page = $this->renderPage(['p' => (new DeferProp(static fn (): int => 1))->once()]);

        $this->assertArrayNotHasKey('p', $page['props']);
        $this->assertSame(['default' => ['p']], $page['deferredProps']);
        $this->assertSame(['p' => ['prop' => 'p', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testADeferredOnceTheClientHasIsNotListedAsDeferredButStaysOnce(): void
    {
        $page = $this->renderPage(
            ['p' => (new DeferProp(static fn (): int => 1))->once()],
            ['X-Inertia-Except-Once-Props' => 'p'],
        );

        $this->assertArrayNotHasKey('p', $page['props']);
        $this->assertArrayNotHasKey('deferredProps', $page);
        $this->assertArrayHasKey('p', $page['onceProps']);
    }

    public function testAMergeOnceIsSentListedAsMergeAndOnceAndSkippedWhenTheClientHasIt(): void
    {
        $prop = (new MergeProp([1]))->once();

        $first = $this->renderPage(['m' => $prop]);
        $later = $this->renderPage(['m' => $prop], ['X-Inertia-Except-Once-Props' => 'm']);

        $this->assertSame([1], $first['props']['m']);
        $this->assertSame(['m'], $first['mergeProps']);
        $this->assertArrayHasKey('m', $first['onceProps']);
        $this->assertArrayNotHasKey('m', $later['props']);
    }

    public function testAnOptionalOnceIsExcludedOnAFirstVisitAndListed(): void
    {
        $calls = $this->newCalls();
        $page  = $this->renderPage(['o' => (new OptionalProp($this->recording($calls, 'o', 1)))->once()]);

        $this->assertArrayNotHasKey('o', $page['props']);
        $this->assertCount(0, $calls);
        $this->assertSame(['o' => ['prop' => 'o', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testOnceSwitchedOffRestoresThePlainBehaviour(): void
    {
        $page = $this->renderPage(
            ['m' => (new MergeProp([1]))->once(false)],
            ['X-Inertia-Except-Once-Props' => 'm'],
        );

        $this->assertSame([1], $page['props']['m']);
        $this->assertArrayNotHasKey('onceProps', $page);
    }

    public function testOnceTakesTheKeyAndTheExpiryInOneCall(): void
    {
        Time::setTestNow('2030-01-01 00:00:00', 'UTC');
        $now = Time::now()->getTimestamp();

        $page = $this->renderPage(['p' => (new MergeProp([1]))->once(true, 'k', 60)]);

        $this->assertSame(['k' => ['prop' => 'p', 'expiresAt' => ($now + 60) * 1000]], $page['onceProps']);
    }

    public function testEachOnceModifierReturnsANewInstanceAndLeavesTheOriginal(): void
    {
        $original = new DeferProp(static fn (): int => 1);

        foreach ([$original->once(), $original->once()->as('k'), $original->once()->fresh(), $original->once()->until(5)] as $copy) {
            $this->assertNotSame($original, $copy);
        }

        $this->assertFalse($original->shouldResolveOnce());
        $this->assertNull($original->getKey());
        $this->assertFalse($original->shouldBeRefreshed());
        $this->assertNull($original->expiresAt());
    }

    public function testAClosureReturningAOncePropListsTheUnwrappedProp(): void
    {
        $page = $this->renderPage(['p' => static fn (): OnceProp => (new OnceProp(static fn (): string => 'v'))->as('k')]);

        $this->assertSame('v', $page['props']['p']);
        $this->assertSame(['k' => ['prop' => 'p', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testAnIntegerBackedEnumKeyIsListedAsAString(): void
    {
        $page = $this->renderPage(['p' => (new OnceProp(static fn (): int => 1))->as(OnceKeyInt::One)]);

        $this->assertSame(['1' => ['prop' => 'p', 'expiresAt' => null]], $page['onceProps']);
    }

    public function testADeferringDeferrableIsListedAsMergedOnlyWhileItMerges(): void
    {
        $make = static fn (bool $merges): Deferrable => new class ($merges) implements Deferrable, Mergeable {
            public function __construct(private readonly bool $merges)
            {
            }

            public function shouldDefer(): bool
            {
                return true;
            }

            public function group(): string
            {
                return 'default';
            }

            public function merge(): static
            {
                return $this;
            }

            public function shouldMerge(): bool
            {
                return $this->merges;
            }

            public function shouldDeepMerge(): bool
            {
                return false;
            }

            public function matchesOn(): array
            {
                return [];
            }

            public function appendsAtRoot(): bool
            {
                return true;
            }

            public function prependsAtRoot(): bool
            {
                return false;
            }

            public function appendsAtPaths(): array
            {
                return [];
            }

            public function prependsAtPaths(): array
            {
                return [];
            }
        };

        $merging    = $this->renderPage(['p' => $make(true)]);
        $notMerging = $this->renderPage(['p' => $make(false)]);

        $this->assertSame(['p'], $merging['mergeProps']);
        $this->assertSame(['default' => ['p']], $merging['deferredProps']);
        $this->assertArrayNotHasKey('mergeProps', $notMerging);
        $this->assertSame(['default' => ['p']], $notMerging['deferredProps']);
    }

    public function testExpiryFollowsTheClock(): void
    {
        Time::setTestNow('2030-01-01 00:00:00', 'UTC');
        $now  = Time::now()->getTimestamp();
        $prop = new MergeProp([1]);

        $this->assertSame(($now + 60) * 1000, $prop->once()->until(60)->expiresAt());
        $this->assertSame(($now + 120) * 1000, $prop->once()->until(new DateInterval('PT2M'))->expiresAt());
        $this->assertSame(($now + 90) * 1000, $prop->once()->until(new DateTimeImmutable('@' . ($now + 90)))->expiresAt());
        $this->assertSame($now * 1000, $prop->once()->until(new DateTimeImmutable('@' . ($now - 500)))->expiresAt());
        $this->assertNull($prop->once()->expiresAt());
    }

    public function testTheKeyAcceptsEnumsByValueOrName(): void
    {
        $prop = new MergeProp([1]);

        $this->assertSame('plans', $prop->as(OnceKeyBacked::Plans)->getKey());
        $this->assertSame('Pure', $prop->as(OnceKeyPure::Pure)->getKey());
    }

    public function testTheExpiryAppearsInThePageMetadata(): void
    {
        Time::setTestNow('2030-01-01 00:00:00', 'UTC');
        $now = Time::now()->getTimestamp();

        $page = $this->renderPage(['p' => (new OnceProp(static fn (): int => 1))->until(60)]);

        $this->assertSame(($now + 60) * 1000, $page['onceProps']['p']['expiresAt']);
    }

    private function rescuingThrow(string $message = 'boom'): DeferProp
    {
        return new DeferProp(static fn (): never => throw new RuntimeException($message), 'default', true);
    }

    public function testARescuedThrowOnAPartialReloadDropsThePropListsItAndLogsAnError(): void
    {
        $page = $this->renderPage(
            ['ok' => 1, 'stats' => $this->rescuingThrow('boom-dropped')],
            $this->partial(['X-Inertia-Partial-Data' => 'stats,ok']),
        );

        $this->assertSame(['ok' => 1], $page['props']);
        $this->assertSame(['stats'], $page['rescuedProps']);
        $this->assertLogContains('error', RuntimeException::class . ': boom-dropped');
    }

    public function testTheRescuedLogLineNamesTheFileAndLineOfTheThrow(): void
    {
        $line = __LINE__ + 2;
        $prop = new DeferProp(
            static fn (): never => throw new RuntimeException('located-failure'),
            'default',
            true,
        );

        $this->renderPage(['stats' => $prop], $this->partial(['X-Inertia-Partial-Data' => 'stats']));

        $this->assertLogContains('error', 'in ' . clean_path(__FILE__) . ' on line ' . $line . '.');
    }

    public function testARescuingPropThatIsNotRescuingLetsTheThrowPropagate(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->renderPage(
            ['stats' => new DeferProp(static fn (): never => throw new RuntimeException('boom'), 'default', false)],
            $this->partial(['X-Inertia-Partial-Data' => 'stats']),
        );
    }

    public function testARescuingPropThatReturnsNormallyIsSentWithoutARescuedList(): void
    {
        $page = $this->renderPage(
            ['stats' => new DeferProp(static fn (): int => 5, 'default', true)],
            $this->partial(['X-Inertia-Partial-Data' => 'stats']),
        );

        $this->assertSame(['stats' => 5], $page['props']);
        $this->assertArrayNotHasKey('rescuedProps', $page);
    }

    public function testEveryRescuedPropIsListedInPropOrder(): void
    {
        $page = $this->renderPage(
            ['b' => $this->rescuingThrow('b-failed'), 'ok' => 1, 'a' => $this->rescuingThrow('a-failed')],
            $this->partial(['X-Inertia-Partial-Data' => 'a,b,ok']),
        );

        $this->assertSame(['ok' => 1], $page['props']);
        $this->assertSame(['b', 'a'], $page['rescuedProps']);
    }

    public function testARescuedThrowInANestedPropIsListedUnderItsDotPath(): void
    {
        $page = $this->renderPage(
            ['a' => ['b' => $this->rescuingThrow('nested-failed'), 'c' => 2]],
            $this->partial(['X-Inertia-Partial-Data' => 'a']),
        );

        $this->assertSame(['a' => ['c' => 2]], $page['props']);
        $this->assertSame(['a.b'], $page['rescuedProps']);
    }

    public function testAClosureReturningARescuingThrowingPropKeepsTheKeyAsNullAndListsIt(): void
    {
        $rescuing = $this->rescuingThrow('closure-failed');

        $page = $this->renderPage(
            ['stats' => static fn (): DeferProp => $rescuing],
            $this->partial(['X-Inertia-Partial-Data' => 'stats']),
        );

        $this->assertSame(['stats' => null], $page['props']);
        $this->assertSame(['stats'], $page['rescuedProps']);
    }

    public function testRescuedPropsComeAfterTheMergeListsAndBeforeOnceProps(): void
    {
        $page = $this->renderPage(
            [
                'feed'  => new MergeProp([1]),
                'stats' => $this->rescuingThrow('order-failed'),
                'once'  => new OnceProp(static fn (): int => 2),
            ],
            $this->partial(['X-Inertia-Partial-Data' => 'feed,stats,once']),
        );

        $this->assertSame(['mergeProps', 'rescuedProps', 'onceProps'], array_values(array_intersect(array_keys($page), ['mergeProps', 'rescuedProps', 'onceProps'])));
    }

    public function testAThrowFromAPropThatIsNotRescuingIsNeverSwallowedOrLogged(): void
    {
        $throwing = static fn (): never => throw new RuntimeException('not-rescued');

        foreach ([new OptionalProp($throwing), $throwing, new DeferProp($throwing)] as $prop) {
            try {
                $this->renderPage(['p' => $prop], $this->partial(['X-Inertia-Partial-Data' => 'p']));
                $this->fail('The throw was swallowed.');
            } catch (RuntimeException $e) {
                $this->assertSame('not-rescued', $e->getMessage());
            }
        }

        $this->assertFalse(TestLogger::didLog('error', 'not-rescued', false));
    }

    public function testARescuingDeferPropLeftOutOfAFullRenderIsNotCalledAndNotListedAsRescued(): void
    {
        $calls = $this->newCalls();
        $prop  = new DeferProp($this->recording($calls, 'stats', 1), 'default', true);

        $page = $this->renderPage(['stats' => $prop]);

        $this->assertSame([], $page['props']);
        $this->assertCount(0, $calls);
        $this->assertArrayNotHasKey('rescuedProps', $page);
    }

    public function testADeferPropCarriesItsRescueFlagThroughEveryCopy(): void
    {
        $this->assertFalse((new DeferProp(static fn (): int => 1))->shouldRescue());

        $rescuing = new DeferProp(static fn (): int => 1, 'g', true);

        $this->assertTrue($rescuing->shouldRescue());
        $this->assertContains(Rescuable::class, class_implements($rescuing));
        $this->assertTrue($rescuing->merge()->shouldRescue());
        $this->assertTrue($rescuing->defer('x')->shouldRescue());
        $this->assertTrue($rescuing->once()->shouldRescue());
        $this->assertTrue($rescuing->deepMerge()->append('a')->shouldRescue());
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
    }
}

enum OnceKeyBacked: string
{
    case Plans = 'plans';
}

enum OnceKeyInt: int
{
    case One = 1;
}

enum OnceKeyPure
{
    case Pure;
}
