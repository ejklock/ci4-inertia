<?php

declare(strict_types=1);

namespace Ejklock\Ci4Inertia\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class PackageDoesNotDependOnAppTest
{
    public function testThePackageDoesNotDependOnTheApp(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Ejklock\Ci4Inertia'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App'))
            ->because('the package ships on its own and must not know the application that uses it');
    }
}
