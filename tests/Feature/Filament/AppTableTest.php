<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Resources\AppResource\Pages\ListApps;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class AppTableTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['channels' => [], 'connections' => 0])]);
    }

    public function test_it_lists_applications(): void
    {
        $apps = App::factory()->count(3)->create();

        Livewire::test(ListApps::class)->assertCanSeeTableRecords($apps);
    }

    public function test_the_key_is_masked_in_the_listing(): void
    {
        $app = App::create(['name' => 'masked']);

        Livewire::test(ListApps::class)
            ->assertDontSee($app->key)
            ->assertSee(substr($app->key, 0, 6));
    }

    public function test_an_application_can_be_deactivated_from_the_row(): void
    {
        $app = App::create(['name' => 'live']);

        Livewire::test(ListApps::class)
            ->callAction(TestAction::make('activation')->table($app));

        $this->assertFalse($app->fresh()->active);
    }

    public function test_an_application_can_be_activated_from_the_row(): void
    {
        $app = App::create(['name' => 'paused', 'active' => false]);

        Livewire::test(ListApps::class)
            ->callAction(TestAction::make('activation')->table($app));

        $this->assertTrue($app->fresh()->active);
    }

    public function test_the_row_action_is_labelled_per_record(): void
    {
        $active = App::create(['name' => 'active-one']);
        $inactive = App::create(['name' => 'inactive-one', 'active' => false]);

        Livewire::test(ListApps::class)
            ->assertActionHasLabel(TestAction::make('activation')->table($active), 'Deactivate')
            ->assertActionHasLabel(TestAction::make('activation')->table($inactive), 'Activate')
            ->assertActionHasColor(TestAction::make('activation')->table($active), 'warning')
            ->assertActionHasColor(TestAction::make('activation')->table($inactive), 'success');
    }

    public function test_the_status_filter_narrows_the_listing(): void
    {
        $active = App::create(['name' => 'running']);
        $inactive = App::create(['name' => 'stopped', 'active' => false]);

        Livewire::test(ListApps::class)
            ->filterTable('active', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$inactive])
            ->filterTable('active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active]);
    }

    public function test_applications_can_be_deactivated_in_bulk(): void
    {
        $apps = App::factory()->count(3)->create(['active' => true]);

        Livewire::test(ListApps::class)
            ->selectTableRecords($apps->pluck('id')->all())
            ->callAction(TestAction::make('deactivate')->table()->bulk());

        $this->assertSame(0, App::query()->where('active', true)->count());
    }

    public function test_deleting_from_the_row_removes_the_application(): void
    {
        $app = App::create(['name' => 'doomed']);

        Livewire::test(ListApps::class)
            ->callAction(TestAction::make('delete')->table($app));

        $this->assertDatabaseMissing('patchbay_apps', ['id' => $app->id]);
    }

    public function test_an_empty_list_offers_the_first_application(): void
    {
        Livewire::test(ListApps::class)
            ->assertSee('No applications yet')
            ->assertSee('New application');
    }
}
