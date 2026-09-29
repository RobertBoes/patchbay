<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Reverb\Protocols\Pusher\Channels\Channel;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Mockery;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Internal\InternalApp;
use RobertBoes\Patchbay\Internal\StatsBroadcaster;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Tests\TestCase;

class StatsBroadcasterTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    protected array $broadcast = [];

    protected function broadcaster(): StatsBroadcaster
    {
        return $this->app->make(StatsBroadcaster::class);
    }

    /**
     * @param  array<string, int>  $channels  Channel name to subscriber count.
     */
    protected function fakeServer(array $channels = [], int $connections = 0, bool $watching = true): void
    {
        $internalChannel = Mockery::mock(Channel::class);
        $internalChannel->shouldReceive('connections')
            ->andReturn($watching ? ['a-connection'] : []);
        $internalChannel->shouldReceive('broadcastToAll')
            ->andReturnUsing(function (array $payload) {
                $this->broadcast[] = $payload;
            });

        $open = [];
        foreach ($channels as $name => $count) {
            $channel = Mockery::mock(Channel::class);
            $channel->shouldReceive('name')->andReturn($name);
            $channel->shouldReceive('connections')->andReturn(array_fill(0, $count, 'c'));
            $open[] = $channel;
        }

        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('for')->andReturnUsing(function ($application) use ($manager, $internalChannel) {
            $manager->forInternal = $application->id() === InternalApp::ID;

            return $manager;
        });
        $manager->shouldReceive('find')->andReturn($internalChannel);
        $manager->shouldReceive('all')->andReturn($open);
        $manager->shouldReceive('connections')->andReturn(array_fill(0, $connections, 'c'));

        $this->app->instance(ChannelManager::class, $manager);
    }

    protected function load(App $app): void
    {
        $this->app->make(Registry::class)->put(
            $this->app->make(AppSource::class)->loadById($app->id),
        );
    }

    public function test_it_pushes_what_the_server_is_holding(): void
    {
        $this->fakeServer(['orders' => 3, 'presence-chat' => 2], connections: 5);

        $app = App::create(['name' => 'one']);
        $this->load($app);

        $this->assertTrue($this->broadcaster()->publish());

        $payload = $this->broadcast[0];
        $this->assertSame(StatsBroadcaster::EVENT, $payload['event']);
        $this->assertSame(InternalApp::CHANNEL, $payload['channel']);

        $data = json_decode($payload['data'], true);
        $this->assertCount(1, $data['apps']);
        $this->assertSame($app->id, $data['apps'][0]['id']);
        $this->assertSame(5, $data['apps'][0]['connections']);
        $this->assertSame(
            [['name' => 'orders', 'subscribers' => 3, 'presence' => false],
                ['name' => 'presence-chat', 'subscribers' => 2, 'presence' => true]],
            $data['apps'][0]['channels'],
        );
    }

    public function test_it_does_not_report_on_the_panels_own_application(): void
    {
        $this->fakeServer(connections: 1);

        $this->app->make(Registry::class)->put($this->app->make(InternalApp::class)->application());

        $this->broadcaster()->publish();

        $data = json_decode($this->broadcast[0]['data'], true);
        $this->assertSame([], $data['apps']);
    }

    public function test_an_unchanged_reading_is_not_pushed_again(): void
    {
        $this->fakeServer(['orders' => 3], connections: 1);

        $this->load(App::create(['name' => 'one']));

        $broadcaster = $this->broadcaster();

        $this->assertTrue($broadcaster->publish());
        $this->assertFalse($broadcaster->publish(), 'An idle server should push nothing.');
        $this->assertCount(1, $this->broadcast);
    }

    public function test_nothing_is_pushed_when_nobody_is_watching(): void
    {
        $this->fakeServer(watching: false);

        $this->load(App::create(['name' => 'one']));

        $this->assertFalse($this->broadcaster()->publish());
        $this->assertSame([], $this->broadcast);
    }

    public function test_nothing_is_pushed_outside_the_running_server(): void
    {
        // ChannelManager is only bound inside the server.
        $this->assertFalse($this->app->bound(ChannelManager::class));
        $this->assertFalse($this->broadcaster()->publish());
    }

    public function test_nothing_is_pushed_when_the_internal_application_is_off(): void
    {
        config()->set('patchbay.internal.enabled', false);
        $this->fakeServer();

        $this->assertFalse($this->broadcaster()->publish());
    }
}
