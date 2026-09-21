<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Mockery;
use RobertBoes\Patchbay\ConnectionTerminator;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Reload\AppChange;
use RobertBoes\Patchbay\Reloader;
use RobertBoes\Patchbay\Tests\TestCase;

class ConnectionTerminatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>  $connectionIds
     */
    protected function fakeChannelManager(array $connectionIds, array &$disconnected): void
    {
        $connections = [];

        foreach ($connectionIds as $id) {
            $connection = Mockery::mock();
            $connection->shouldReceive('disconnect')->andReturnUsing(
                function () use ($id, &$disconnected) {
                    $disconnected[] = $id;
                },
            );
            $connections[$id] = $connection;
        }

        $channels = Mockery::mock(ChannelManager::class);
        $channels->shouldReceive('for')->andReturn($channels);
        $channels->shouldReceive('connections')->andReturn($connections);

        $this->app->instance(ChannelManager::class, $channels);
    }

    public function test_it_disconnects_every_connection_for_the_application(): void
    {
        $disconnected = [];
        $this->fakeChannelManager(['a', 'b'], $disconnected);

        $app = App::create(['name' => 'test']);
        $application = $this->app->make(\RobertBoes\Patchbay\Contracts\AppSource::class)->loadById($app->id);

        $closed = $this->app->make(ConnectionTerminator::class)->terminate($application);

        $this->assertSame(2, $closed);
        $this->assertSame(['a', 'b'], $disconnected);
    }

    public function test_it_does_nothing_outside_the_running_server(): void
    {
        $app = App::create(['name' => 'test']);
        $application = $this->app->make(\RobertBoes\Patchbay\Contracts\AppSource::class)->loadById($app->id);

        $this->assertFalse($this->app->bound(ChannelManager::class));
        $this->assertSame(0, $this->app->make(ConnectionTerminator::class)->terminate($application));
    }

    public function test_revoking_an_application_disconnects_its_clients(): void
    {
        $disconnected = [];
        $this->fakeChannelManager(['live-client'], $disconnected);

        $app = App::create(['name' => 'test']);
        $reloader = $this->app->make(Reloader::class);
        $reloader->reloadAll();

        $reloader->apply(AppChange::delete($app->id));

        $this->assertSame(['live-client'], $disconnected, 'Revoking must end access, not just refuse new connections.');
        $this->assertNull($this->app->make(Registry::class)->findById($app->id));
    }

    public function test_termination_can_be_turned_off(): void
    {
        config()->set('patchbay.terminate_on_revoke', false);
        $this->app->forgetInstance(Reloader::class);

        $disconnected = [];
        $this->fakeChannelManager(['live-client'], $disconnected);

        $app = App::create(['name' => 'test']);
        $reloader = $this->app->make(Reloader::class);
        $reloader->reloadAll();

        $reloader->apply(AppChange::delete($app->id));

        $this->assertSame([], $disconnected);
    }
}
