<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RobertBoes\Patchbay\Contracts\ReloadDriver;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Reload\AppChange;
use RobertBoes\Patchbay\Reload\CacheReloadDriver;
use RobertBoes\Patchbay\Reloader;
use RobertBoes\Patchbay\Tests\TestCase;

class ReloadTest extends TestCase
{
    use RefreshDatabase;

    protected function driver(): CacheReloadDriver
    {
        return $this->app->make(ReloadDriver::class);
    }

    protected function reloader(): Reloader
    {
        return $this->app->make(Reloader::class);
    }

    /**
     * @return array{changes: array<int, AppChange>, desynced: bool}
     */
    protected function drain(): array
    {
        $changes = [];
        $desynced = false;

        $this->driver()->drain(
            function (AppChange $change) use (&$changes) {
                $changes[] = $change;
            },
            function () use (&$desynced) {
                $desynced = true;
            },
        );

        return ['changes' => $changes, 'desynced' => $desynced];
    }

    public function test_listening_drains_changes_on_the_loop_it_was_given(): void
    {
        config()->set('patchbay.reload.drivers.cache.interval', 0);
        config()->set('patchbay.reload.reconcile_every', null);
        $this->app->forgetInstance(ReloadDriver::class);

        $changes = [];

        $this->driver()->listen(
            function (AppChange $change) use (&$changes) {
                $changes[] = $change;
                $this->loop->stop();
            },
            fn() => null,
        );

        $app = App::create(['name' => 'test']);

        $this->loop->addTimer(1, fn() => $this->loop->stop());
        $this->loop->run();

        $this->assertCount(1, $changes);
        $this->assertSame($app->id, $changes[0]->id);
    }

    public function test_creating_an_application_publishes_an_upsert(): void
    {
        $app = App::create(['name' => 'test']);

        $result = $this->drain();

        $this->assertFalse($result['desynced']);
        $this->assertCount(1, $result['changes']);
        $this->assertSame($app->id, $result['changes'][0]->id);
        $this->assertSame(AppChange::UPSERT, $result['changes'][0]->operation);
    }

    public function test_renaming_an_application_publishes_nothing(): void
    {
        $app = App::create(['name' => 'test']);
        $this->drain();

        $app->update(['name' => 'renamed']);

        $this->assertCount(0, $this->drain()['changes'], 'A name is not read by the server.');
    }

    public function test_changing_a_setting_the_server_reads_publishes_an_upsert(): void
    {
        $app = App::create(['name' => 'test']);
        $this->drain();

        $app->update(['ping_interval' => 90]);

        $this->assertCount(1, $this->drain()['changes']);
    }

    public function test_deactivating_an_application_publishes_a_delete(): void
    {
        $app = App::create(['name' => 'test']);
        $this->drain();

        $app->update(['active' => false]);

        $changes = $this->drain()['changes'];
        $this->assertCount(1, $changes);
        $this->assertTrue($changes[0]->isDelete());
    }

    public function test_nothing_is_drained_when_nothing_changed(): void
    {
        App::create(['name' => 'test']);
        $this->drain();

        $result = $this->drain();

        $this->assertCount(0, $result['changes']);
        $this->assertFalse($result['desynced']);
    }

    public function test_it_asks_for_a_full_reload_when_changes_fall_off_the_backlog(): void
    {
        config()->set('patchbay.reload.drivers.cache.backlog', 3);
        $this->app->forgetInstance(ReloadDriver::class);

        $driver = $this->driver();
        $driver->drain(fn() => null, fn() => null);

        // More changes than the backlog can hold, so the log no longer covers
        // everything that happened since the last check.
        foreach (range(1, 6) as $i) {
            $driver->publish(AppChange::upsert("app-{$i}"));
        }

        $desynced = false;
        $driver->drain(fn() => null, function () use (&$desynced) {
            $desynced = true;
        });

        $this->assertTrue($desynced, 'A truncated log must trigger a full reload, not a partial replay.');
    }

    public function test_the_reloader_applies_a_delete_without_touching_the_database(): void
    {
        $app = App::create(['name' => 'test']);
        $reloader = $this->reloader();
        $reloader->reloadAll();

        DB::enableQueryLog();
        $reloader->apply(AppChange::delete($app->id));

        $this->assertCount(0, DB::getQueryLog(), 'A delete is already known; it needs no load.');
        $this->assertNull($this->app->make(Registry::class)->findById($app->id));
    }

    public function test_the_reloader_loads_only_the_changed_application(): void
    {
        App::factory()->count(5)->create();
        $app = App::create(['name' => 'changed']);

        $reloader = $this->reloader();
        $reloader->reloadAll();

        $app->update(['ping_interval' => 120]);

        DB::enableQueryLog();
        $reloader->apply(AppChange::upsert($app->id));
        $queries = DB::getQueryLog();

        $this->assertCount(1, $queries, 'A single change should cost a single load.');
        $this->assertSame(
            120,
            $this->app->make(Registry::class)->findById($app->id)->pingInterval(),
        );
    }

    public function test_an_upsert_for_a_now_inactive_application_removes_it(): void
    {
        $app = App::create(['name' => 'test']);
        $reloader = $this->reloader();
        $reloader->reloadAll();

        // Deactivated between the signal being published and it being applied.
        $app->updateQuietly(['active' => false]);

        $reloader->apply(AppChange::upsert($app->id));

        $this->assertNull($this->app->make(Registry::class)->findById($app->id));
    }

    public function test_reloading_all_marks_the_registry_complete(): void
    {
        App::factory()->count(3)->create();

        $this->reloader()->reloadAll();

        $registry = $this->app->make(Registry::class);
        $this->assertTrue($registry->isComplete());
        $this->assertSame(3, $registry->count());
    }
}
