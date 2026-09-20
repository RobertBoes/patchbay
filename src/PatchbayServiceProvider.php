<?php

namespace RobertBoes\Patchbay;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PatchbayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('patchbay')
            ->hasConfigFile('patchbay');
    }
}
