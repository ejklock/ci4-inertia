<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Ejklock\Ci4Inertia\Filters\EncryptHistoryFilter;

/**
 * Verifies `Ejklock\Ci4Inertia\Filters\EncryptHistoryFilter`: a page rendered behind it
 * asks the client to encrypt its history state, a page rendered elsewhere does not, and
 * the response itself is left as the controller built it.
 *
 * @internal
 */
final class EncryptHistoryFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $originalRootView;
    private bool $originalEncryptHistory = false;

    /**
     * @var array<string, string>
     */
    private array $originalAliases;

    protected function setUp(): void
    {
        parent::setUp();

        $inertia                      = config('Inertia');
        $this->originalRootView       = $inertia->rootView;
        $this->originalEncryptHistory = $inertia->encryptHistory;
        $inertia->rootView            = 'Ejklock\Ci4InertiaTestSupport\Views\shell';
        $inertia->encryptHistory      = false;

        $filters                            = config('Filters');
        $this->originalAliases              = $filters->aliases;
        $filters->aliases['encryptHistory'] = EncryptHistoryFilter::class;

        $this->resetPerRequestServices();
    }

    protected function tearDown(): void
    {
        $inertia                   = config('Inertia');
        $inertia->rootView         = $this->originalRootView;
        $inertia->encryptHistory   = $this->originalEncryptHistory;
        config('Filters')->aliases = $this->originalAliases;

        $this->resetPerRequestServices();

        parent::tearDown();
    }

    private function resetPerRequestServices(): void
    {
        BaseService::resetSingle('response');
        BaseService::resetSingle('inertia');
        BaseService::resetSingle('routes');
        BaseService::resetSingle('router');
    }

    /**
     * @param array<string, string> $options
     *
     * @return array<string, mixed>
     */
    private function pageRenderedBy(string $path, array $options): array
    {
        $this->withRoutes([
            ['GET', $path, static fn () => service('inertia')
                ->render('Page/Index', [])
                ->toResponse(service('request'), service('response')), $options],
        ]);

        $result = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->get('/' . $path);

        $result->assertStatus(200);

        return json_decode((string) $result->response()->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testAPageBehindTheFilterAsksTheClientToEncryptItsHistory(): void
    {
        $page = $this->pageRenderedBy('secret', ['filter' => 'encryptHistory']);

        $this->assertTrue($page['encryptHistory']);
    }

    public function testAPageWithoutTheFilterCarriesNoEncryptHistoryKey(): void
    {
        $page = $this->pageRenderedBy('open', []);

        $this->assertArrayNotHasKey('encryptHistory', $page);
    }

    public function testTheFilterLeavesTheResponseUnchanged(): void
    {
        $response = service('response')->setStatusCode(201)->setBody('as built');

        $returned = (new EncryptHistoryFilter())->after(service('request'), $response);

        $this->assertSame($response, $returned);
        $this->assertSame(201, $returned->getStatusCode());
        $this->assertSame('as built', (string) $returned->getBody());
    }
}
