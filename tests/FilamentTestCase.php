<?php

namespace RobertBoes\Patchbay\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\View;
use Livewire\Mechanisms\DataStore;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Support\Facades\Schema;
use RobertBoes\Patchbay\Tests\Fixtures\TestPanelProvider;
use RobertBoes\Patchbay\Tests\Fixtures\User;

/**
 * Base for tests that exercise the Filament dashboard.
 *
 * Kept separate from the plain TestCase so the rest of the suite proves the
 * package works with Filament absent, which is the state most applications
 * installing it will be in.
 */
abstract class FilamentTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire keeps per-component state in a DataStore it registers as a
        // shared container instance. Testbench finishes building the container
        // after that registration and the instance does not survive, so every
        // lookup returns a fresh store and nothing Livewire writes is ever read
        // back. Re-registering restores what the service provider intended.
        app(DataStore::class)->register();

        // The session middleware that shares the error bag does not run for a
        // component resolved outside a request.
        View::share('errors', new ViewErrorBag());

        $this->actingAs(User::create([
            'name' => 'Operator',
            'email' => 'operator@example.test',
            'password' => bcrypt('password'),
        ]));
    }

    protected function getPackageProviders($app): array
    {
        return array_merge([
            \Livewire\LivewireServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\QueryBuilder\QueryBuilderServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            TestPanelProvider::class,
        ], parent::getPackageProviders($app));
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        // Declared here rather than pulled from Laravel's own migrations: the
        // panel needs somewhere to authenticate against, and nothing else
        // about a host application's users table concerns this package.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
}
