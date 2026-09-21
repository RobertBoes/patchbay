<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RobertBoes\Patchbay\Filament\Resources\AppResource;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class PanelBootTest extends FilamentTestCase
{
    use RefreshDatabase;

    public function test_the_plugin_registers_the_resource(): void
    {
        $this->assertContains(
            AppResource::class,
            filament()->getPanel('testing')->getResources(),
        );
    }
}
