<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;
use Ejklock\Ci4Inertia\Ssr\Gateway;
use Ejklock\Ci4Inertia\Ssr\HasHealthCheck;
use Ejklock\Ci4Inertia\Ssr\Response;

/**
 * Verifies `spark inertia:check-ssr` reports the shared gateway's health through its message and
 * exit code.
 *
 * @internal
 */
final class CheckSsrTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    protected function tearDown(): void
    {
        Services::reset(true);

        parent::tearDown();
    }

    public function testAHealthyServerIsReportedAsRunning(): void
    {
        $exit = $this->check($this->healthCheckingGateway(true));

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Inertia SSR server is running.', $this->getStreamFilterBuffer());
        $this->assertStringNotContainsString('not running', $this->getStreamFilterBuffer());
    }

    public function testAnUnhealthyServerIsReportedAsNotRunning(): void
    {
        $exit = $this->check($this->healthCheckingGateway(false));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Inertia SSR server is not running.', $this->getStreamFilterBuffer());
    }

    public function testAGatewayWithoutAHealthCheckIsReportedAsUnsupported(): void
    {
        $exit = $this->check(new class () implements Gateway {
            public function dispatch(array $page): ?Response
            {
                return null;
            }
        });

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The SSR gateway does not support health checks.', $this->getStreamFilterBuffer());
    }

    private function check(Gateway $gateway): int
    {
        Services::injectMock('inertiaSsrGateway', $gateway);

        return service('commands')->run('inertia:check-ssr', []);
    }

    private function healthCheckingGateway(bool $healthy): Gateway
    {
        return new class ($healthy) implements Gateway, HasHealthCheck {
            public function __construct(private readonly bool $healthy)
            {
            }

            public function dispatch(array $page): ?Response
            {
                return null;
            }

            public function isHealthy(): bool
            {
                return $this->healthy;
            }
        };
    }
}
