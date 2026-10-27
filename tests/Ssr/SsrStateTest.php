<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Ssr;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Test\CIUnitTestCase;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\Response;
use Ejklock\Ci4Inertia\Ssr\SsrState;
use RuntimeException;

/**
 * Verifies that the per-request SSR state sends each page to the gateway once and resolves the
 * gateway when it dispatches.
 *
 * @internal
 */
final class SsrStateTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        BaseService::resetSingle('inertiaSsrState');
        BaseService::resetSingle('inertiaSsrGateway');

        parent::tearDown();
    }

    private function inject(?Response $response, bool $throws = false): object
    {
        $gateway = new class ($response, $throws) implements Gateway {
            /**
             * @var list<array<string, mixed>>
             */
            public array $received = [];

            public function __construct(private readonly ?Response $response, private readonly bool $throws)
            {
            }

            public function dispatch(array $page): ?Response
            {
                $this->received[] = $page;

                if ($this->throws) {
                    throw new RuntimeException('down');
                }

                return $this->response;
            }
        };
        BaseService::injectMock('inertiaSsrGateway', $gateway);

        return $gateway;
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $name = 'a'): array
    {
        return ['component' => 'C', 'props' => ['nested' => ['name' => $name]]];
    }

    public function testDispatchesAPageOnceAndReturnsTheSameResponse(): void
    {
        $response = new Response('h', 'b');
        $gateway  = $this->inject($response);
        $state    = new SsrState();
        $state->setPage($this->page());

        $this->assertSame($response, $state->dispatch());
        $this->assertSame($response, $state->dispatch());
        $this->assertCount(1, $gateway->received);
    }

    public function testANullResponseIsNotDispatchedAgain(): void
    {
        $gateway = $this->inject(null);
        $state   = new SsrState();
        $state->setPage($this->page());

        $this->assertNull($state->dispatch());
        $this->assertNull($state->dispatch());
        $this->assertCount(1, $gateway->received);
    }

    public function testSettingAnEqualPageDoesNotDispatchAgain(): void
    {
        $gateway = $this->inject(new Response('h', 'b'));
        $state   = new SsrState();
        $state->setPage($this->page())->dispatch();

        $state->setPage($this->page())->dispatch();

        $this->assertCount(1, $gateway->received);
    }

    public function testAPageDifferingInANestedValueIsDispatchedAnew(): void
    {
        $gateway = $this->inject(new Response('h', 'b'));
        $state   = new SsrState();
        $state->setPage($this->page('a'))->dispatch();

        $state->setPage($this->page('b'))->dispatch();

        $this->assertSame([$this->page('a'), $this->page('b')], $gateway->received);
    }

    public function testAPageDifferingOnlyInTheTypeOfANestedValueIsDispatchedAnew(): void
    {
        $gateway = $this->inject(new Response('h', 'b'));
        $state   = new SsrState();
        $state->setPage(['component' => 'C', 'props' => ['count' => 1]])->dispatch();

        $state->setPage(['component' => 'C', 'props' => ['count' => '1']])->dispatch();

        $this->assertCount(2, $gateway->received);
    }

    public function testAPageDifferingOnlyInKeyOrderIsDispatchedAnew(): void
    {
        $gateway = $this->inject(new Response('h', 'b'));
        $state   = new SsrState();
        $state->setPage(['component' => 'C', 'props' => ['x' => 1, 'y' => 2]])->dispatch();

        $state->setPage(['component' => 'C', 'props' => ['y' => 2, 'x' => 1]])->dispatch();

        $this->assertCount(2, $gateway->received);
    }

    public function testChangingThePageForgetsTheRenderUntilItIsDispatched(): void
    {
        $this->inject(new Response('h', 'b'));
        $state = new SsrState();
        $state->setPage($this->page('a'))->dispatch();

        $state->setPage($this->page('b'));

        $this->assertNull($state->response);
    }

    public function testTheGatewayReceivesThePageThatWasSet(): void
    {
        $gateway = $this->inject(null);

        (new SsrState())->setPage($this->page())->dispatch();

        $this->assertSame([$this->page()], $gateway->received);
    }

    public function testAThrowingGatewayReachesTheCallerAndIsNotCalledAgainForTheSamePage(): void
    {
        $gateway = $this->inject(null, true);
        $state   = new SsrState();
        $state->setPage($this->page());

        try {
            $state->dispatch();
            $this->fail('The gateway exception was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('down', $e->getMessage());
        }

        $this->assertNull($state->dispatch());
        $this->assertCount(1, $gateway->received);
    }

    public function testTheServiceIsSharedUnlessAFreshOneIsAsked(): void
    {
        $this->assertSame(service('inertiaSsrState'), service('inertiaSsrState'));
        $this->assertNotSame(service('inertiaSsrState'), service('inertiaSsrState', false));
    }

    public function testAGatewayInjectedAfterTheStateWasBuiltIsDispatchedTo(): void
    {
        $state    = service('inertiaSsrState')->setPage($this->page());
        $response = new Response('h', 'b');
        $this->inject($response);

        $this->assertSame($response, $state->dispatch());
    }
}
