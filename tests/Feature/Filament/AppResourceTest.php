<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\PatchbayPlugin;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages\CreateApp;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages\EditApp;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages\ListApps;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages\ViewApp;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\FilamentTestCase;
use RobertBoes\Patchbay\Tests\Fixtures\FlippableAppPolicy;

class AppResourceTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The panel reads live figures from the running server. Tests must not
        // reach whatever happens to be listening on the machine running them.
        Http::preventStrayRequests();
        Http::fake([
            '*/up' => Http::response('', 200),
            '*/channels*' => Http::response(['channels' => ['orders' => []]]),
            '*/connections*' => Http::response(['connections' => 4]),
        ]);
    }

    public function test_the_list_page_renders(): void
    {
        App::factory()->count(3)->create();

        Livewire::test(ListApps::class)->assertOk();
    }

    public function test_the_create_page_renders(): void
    {
        Livewire::test(CreateApp::class)->assertOk();
    }

    public function test_the_edit_page_renders(): void
    {
        $app = App::create(['name' => 'editable']);

        Livewire::test(EditApp::class, ['record' => $app->id])->assertOk();
    }

    public function test_creating_an_application_mints_credentials(): void
    {
        Livewire::test(CreateApp::class)
            ->fillForm(['name' => 'minted', 'active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $app = App::where('name', 'minted')->firstOrFail();

        $this->assertSame(20, strlen($app->key));
        $this->assertSame(40, strlen($app->secret));
    }

    public function test_a_configured_connection_limit_bounds_the_form(): void
    {
        PatchbayPlugin::current()->connectionLimit(fn() => 5);

        Livewire::test(CreateApp::class)
            ->assertSee('Up to 5.')
            ->fillForm(['name' => 'capped', 'max_connections' => 50])
            ->call('create')
            ->assertHasFormErrors(['max_connections' => 'max']);

        PatchbayPlugin::current()->connectionLimit(null);
    }

    public function test_a_limit_reached_after_the_page_opened_is_explained_not_forbidden(): void
    {
        Gate::policy(App::class, FlippableAppPolicy::class);

        $page = Livewire::test(CreateApp::class)->assertOk();

        // Reached from another tab while this one was open.
        FlippableAppPolicy::$refusal = 'All 3 of your applications are in use.';

        $page->fillForm(['name' => 'one too many'])
            ->call('create')
            ->assertDispatched(
                'notificationSent',
                fn(string $event, array $params) => $params['notification']['title'] === 'All 3 of your applications are in use.',
            );

        $this->assertDatabaseMissing(App::class, ['name' => 'one too many']);

        FlippableAppPolicy::$refusal = null;
    }

    public function test_the_secret_is_hidden_until_revealed(): void
    {
        $app = App::create(['name' => 'secretive']);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertDontSee($app->secret)
            ->callAction('reveal')
            ->assertSee($app->secret);
    }

    public function test_the_reveal_button_flips_on_the_first_click(): void
    {
        $app = App::create(['name' => 'togglable']);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertActionHasLabel('reveal', 'Reveal secret')
            ->callAction('reveal')
            ->assertActionHasLabel('reveal', 'Hide secret')
            ->callAction('reveal')
            ->assertActionHasLabel('reveal', 'Reveal secret');
    }

    public function test_the_activation_button_flips_on_the_first_click(): void
    {
        $app = App::create(['name' => 'activatable']);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertActionHasLabel('activation', 'Deactivate')
            ->assertActionHasColor('activation', 'warning')
            ->callAction('activation')
            ->assertActionHasLabel('activation', 'Activate')
            ->assertActionHasColor('activation', 'success');

        $this->assertFalse($app->fresh()->active);
    }

    public function test_the_env_block_appears_once_revealed(): void
    {
        $app = App::create(['name' => 'enveloped']);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->callAction('reveal')
            ->assertSee('REVERB_APP_ID=' . $app->id)
            ->assertSee('VITE_REVERB_APP_KEY', escape: false);
    }

    public function test_rotating_the_secret_replaces_it(): void
    {
        $app = App::create(['name' => 'rotatable']);
        $original = $app->secret;

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->callAction('rotateSecret')
            ->assertSet('secretRevealed', true);

        $this->assertNotSame($original, $app->fresh()->secret);
    }

    public function test_the_handover_from_creation_is_consumed_once(): void
    {
        $app = App::create(['name' => 'fresh']);

        session()->put('patchbay.reveal', $app->id);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertSet('secretRevealed', true);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertSet('secretRevealed', false);
    }

    public function test_a_deactivated_application_does_not_read_as_an_outage(): void
    {
        $app = App::create(['name' => 'paused', 'active' => false]);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertSee('Inactive')
            ->assertDontSee('Server unreachable');
    }

    public function test_an_unreachable_server_is_named_as_such(): void
    {
        Http::fake(fn() => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $app = App::create(['name' => 'orphaned']);

        Livewire::test(ViewApp::class, ['record' => $app->id])
            ->assertSee('Server unreachable');
    }
}
