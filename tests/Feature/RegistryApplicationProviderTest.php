<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\RegistryApplicationProvider;
use RobertBoes\Patchbay\Tests\TestCase;

class RegistryApplicationProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reverb_resolves_patchbay_as_its_application_provider(): void
    {
        $this->assertInstanceOf(
            RegistryApplicationProvider::class,
            $this->app->make(ApplicationProvider::class),
        );
    }

    public function test_it_finds_an_application_by_key_and_id(): void
    {
        $app = App::create(['name' => 'test']);

        $provider = $this->app->make(RegistryApplicationProvider::class);

        $this->assertSame($app->id, $provider->findByKey($app->key)->id());
        $this->assertSame($app->key, $provider->findById($app->id)->key());
    }

    public function test_it_does_not_serve_inactive_applications(): void
    {
        $app = App::create(['name' => 'test', 'active' => false]);

        $this->expectException(InvalidApplication::class);

        $this->app->make(RegistryApplicationProvider::class)->findByKey($app->key);
    }

    public function test_an_unknown_key_throws(): void
    {
        $this->expectException(InvalidApplication::class);

        $this->app->make(RegistryApplicationProvider::class)->findByKey('nope');
    }

    public function test_a_cold_registry_loads_a_single_application_rather_than_all_of_them(): void
    {
        App::factory()->count(5)->create();
        $wanted = App::create(['name' => 'wanted']);

        $provider = $this->app->make(RegistryApplicationProvider::class);

        DB::enableQueryLog();
        $provider->findByKey($wanted->key);
        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries, 'A registry miss should load one application, not the whole table.');
        $this->assertSame(1, $this->app->make(Registry::class)->count());
    }

    public function test_a_second_lookup_is_served_from_memory(): void
    {
        $app = App::create(['name' => 'test']);
        $provider = $this->app->make(RegistryApplicationProvider::class);
        $provider->findByKey($app->key);

        DB::enableQueryLog();
        $provider->findByKey($app->key);

        $this->assertCount(0, DB::getQueryLog(), 'A warm registry should not hit the database.');
    }

    public function test_a_complete_registry_answers_a_miss_without_touching_the_database(): void
    {
        App::create(['name' => 'test']);
        $provider = $this->app->make(RegistryApplicationProvider::class);
        $provider->all();

        DB::enableQueryLog();

        try {
            $provider->findByKey('does-not-exist');
            $this->fail('Expected InvalidApplication.');
        } catch (InvalidApplication) {
            // expected
        }

        $this->assertCount(0, DB::getQueryLog(), 'An unknown key must not cost a query on every connection.');
    }
}
