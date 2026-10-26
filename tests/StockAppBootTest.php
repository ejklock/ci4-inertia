<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests;

use Config\App;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
final class StockAppBootTest extends TestCase
{
    public function testTheApplicationPathIsTheFrameworksStockApp(): void
    {
        $stockApp = realpath(__DIR__ . '/../vendor/codeigniter4/framework/app') . DIRECTORY_SEPARATOR;

        $this->assertSame($stockApp, APPPATH);
    }

    public function testTheAppConfigComesFromTheFrameworksStockApp(): void
    {
        $stockApp = realpath(__DIR__ . '/../vendor/codeigniter4/framework/app') . DIRECTORY_SEPARATOR;

        $this->assertSame($stockApp . 'Config/App.php', (new ReflectionClass(App::class))->getFileName());
    }
}
