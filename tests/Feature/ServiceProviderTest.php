<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use RobertBoes\Patchbay\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_package_registers_its_config(): void
    {
        $this->assertIsArray(config('patchbay'));
    }
}
