<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use RobertBoes\Patchbay\PatchbayServiceProvider;
use RobertBoes\Patchbay\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_package_registers_its_config(): void
    {
        $this->assertIsArray(config('patchbay'));
    }

    public function test_every_migration_the_package_ships_can_be_published(): void
    {
        // hasMigrations names them one by one, so a new file is invisible to
        // an install until it is listed: the table would simply never exist.
        $shipped = array_map(
            fn(string $path) => basename($path, '.php'),
            glob(__DIR__ . '/../../database/migrations/*.php') ?: [],
        );

        $published = array_map(
            fn(string $path) => basename($path, '.php'),
            array_keys(ServiceProvider::pathsToPublish(
                PatchbayServiceProvider::class,
                'patchbay-migrations',
            )),
        );

        sort($shipped);
        sort($published);

        $this->assertSame($shipped, $published);
        $this->assertContains('create_patchbay_events_table', $published);
    }
}
