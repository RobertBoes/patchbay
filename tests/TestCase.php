<?php

namespace RobertBoes\Patchbay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RobertBoes\Patchbay\PatchbayServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PatchbayServiceProvider::class];
    }
}
