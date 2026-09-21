<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Mockery;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Metrics\MetricsRecorder;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Tests\TestCase;

class MetricsRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function recorder(): MetricsRecorder
    {
        return $this->app->make(MetricsRecorder::class);
    }

    protected function load(App $app): \Laravel\Reverb\Application
    {
        $application = $this->app->make(AppSource::class)->loadById($app->id);

        $this->app->make(Registry::class)->put($application);

        return $application;
    }

    protected function fakeChannels(int $connections, int $channels): void
    {
        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('for')->andReturn($manager);
        $manager->shouldReceive('connections')->andReturn(array_fill(0, $connections, 'connection'));
        $manager->shouldReceive('all')->andReturn(array_fill(0, $channels, 'channel'));

        $this->app->instance(ChannelManager::class, $manager);
    }

    public function test_it_records_a_sample_per_application(): void
    {
        $this->fakeChannels(connections: 3, channels: 2);

        $this->load(App::create(['name' => 'one']));
        $this->load(App::create(['name' => 'two']));

        $this->assertSame(2, $this->recorder()->flush());
        $this->assertSame(2, Metric::query()->count());

        $metric = Metric::query()->first();
        $this->assertSame(3, $metric->connections);
        $this->assertSame(2, $metric->channels);
    }

    public function test_a_sample_records_which_server_it_came_from(): void
    {
        config()->set('patchbay.metrics.server', 'reverb-7');

        $this->fakeChannels(connections: 1, channels: 1);
        $this->load(App::create(['name' => 'one']));

        $this->recorder()->flush();

        $this->assertSame('reverb-7', Metric::query()->firstOrFail()->server);
    }

    public function test_the_server_defaults_to_the_hostname(): void
    {
        $this->fakeChannels(connections: 1, channels: 1);
        $this->load(App::create(['name' => 'one']));

        $this->recorder()->flush();

        $this->assertSame(gethostname(), Metric::query()->firstOrFail()->server);
    }

    public function test_messages_are_counted_in_memory_and_written_on_flush(): void
    {
        $this->fakeChannels(connections: 0, channels: 0);

        $app = $this->load(App::create(['name' => 'chatty']));
        $recorder = $this->recorder();

        $recorder->messageSent($app);
        $recorder->messageSent($app);
        $recorder->messageReceived($app);

        // Nothing is written until the flush; the database must never sit on
        // the path of an individual frame.
        $this->assertSame(0, Metric::query()->count());
        $this->assertSame(['sent' => 2, 'received' => 1], $recorder->pending()[$app->id()]);

        $recorder->flush();

        $metric = Metric::query()->firstOrFail();
        $this->assertSame(2, $metric->messages_sent);
        $this->assertSame(1, $metric->messages_received);
    }

    public function test_message_counters_reset_between_samples(): void
    {
        $this->fakeChannels(connections: 0, channels: 0);

        $app = $this->load(App::create(['name' => 'chatty']));
        $recorder = $this->recorder();

        $recorder->messageSent($app);
        $recorder->flush(Carbon::now()->subMinute());

        $recorder->flush();

        $samples = Metric::query()->orderBy('recorded_at')->get();
        $this->assertSame(1, $samples[0]->messages_sent);
        $this->assertSame(0, $samples[1]->messages_sent, 'A sample counts the window, not everything ever sent.');
    }

    public function test_it_records_nothing_when_there_are_no_applications(): void
    {
        $this->fakeChannels(connections: 0, channels: 0);

        $this->assertSame(0, $this->recorder()->flush());
        $this->assertSame(0, Metric::query()->count());
    }

    public function test_it_samples_zero_outside_the_running_server(): void
    {
        $app = $this->load(App::create(['name' => 'remote']));

        $this->assertFalse($this->app->bound(ChannelManager::class));
        $this->recorder()->flush();

        $this->assertSame(0, Metric::query()->firstOrFail()->connections);
    }

    public function test_pruning_removes_only_old_samples(): void
    {
        $this->fakeChannels(connections: 1, channels: 1);
        $app = $this->load(App::create(['name' => 'aged']));
        $recorder = $this->recorder();

        $recorder->flush(Carbon::now()->subDays(30));
        $recorder->flush(Carbon::now());

        $this->artisan('patchbay:prune-metrics', ['--days' => 7])->assertSuccessful();

        $this->assertSame(1, Metric::query()->count());
    }
}
