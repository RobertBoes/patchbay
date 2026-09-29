<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use RobertBoes\Patchbay\Internal\InternalApp;
use RobertBoes\Patchbay\Metrics\EventRecorder;
use RobertBoes\Patchbay\Metrics\MetricsRecorder;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Event;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Reloader;
use RobertBoes\Patchbay\Server\Health;
use RobertBoes\Patchbay\Server\HealthCheck;
use RobertBoes\Patchbay\Tests\Fixtures\FakeConnection;
use RobertBoes\Patchbay\Tests\TestCase;

class InternalAppTest extends TestCase
{
    use RefreshDatabase;

    protected function internal(): InternalApp
    {
        return $this->app->make(InternalApp::class);
    }

    public function test_its_credentials_come_from_the_application_key(): void
    {
        $key = $this->internal()->key();
        $secret = $this->internal()->secret();

        // Same key, same credentials: every process agrees without storing them.
        $this->assertSame($key, $this->internal()->key());

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('b', 32)));
        $this->app->forgetInstance(InternalApp::class);

        $this->assertNotSame($key, $this->internal()->key());
        $this->assertNotSame($secret, $this->internal()->secret());
    }

    public function test_it_is_not_reachable_without_an_application_key(): void
    {
        config()->set('app.key', '');
        $this->app->forgetInstance(InternalApp::class);

        $this->assertFalse($this->internal()->enabled());
    }

    public function test_it_accepts_no_client_events(): void
    {
        // Nothing but the server publishes to it, so a connected browser must
        // not be able to put anything on its channel.
        $this->assertSame('none', $this->internal()->application()->acceptClientEventsFrom());
    }

    public function test_a_server_holding_only_the_panels_application_still_reads_as_degraded(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 200)]);

        // The server starts with nothing to load, so it holds only its own.
        $reloader = $this->app->make(Reloader::class);
        $reloader->reloadAll();

        // An application exists and is active, but this server has not got it.
        App::factory()->create();

        $this->assertSame(1, $this->app->make(\RobertBoes\Patchbay\Registry::class)->count());
        $this->assertSame(0, $reloader->applicationCount());

        $this->app->make(\RobertBoes\Patchbay\Server\Heartbeat::class)
            ->beat($reloader->applicationCount());

        $this->assertSame(Health::Degraded, $this->app->make(HealthCheck::class)->status());
    }

    public function test_a_server_holding_your_applications_reads_as_operational(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 200)]);

        App::factory()->create();

        $reloader = $this->app->make(Reloader::class);
        $reloader->reloadAll();

        $this->app->make(\RobertBoes\Patchbay\Server\Heartbeat::class)->beat($reloader->applicationCount());

        $this->assertSame(Health::Operational, $this->app->make(HealthCheck::class)->status());
    }

    public function test_its_traffic_is_not_counted(): void
    {
        $this->app->make(Reloader::class)->start();

        $application = $this->internal()->application();
        $connection = new FakeConnection($application);

        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $events->dispatch(new MessageSent($connection, '{"event":"patchbay:stats","data":{}}'));
        $events->dispatch(new MessageReceived($connection, '{"event":"client-anything","data":{}}'));

        $this->app->make(MetricsRecorder::class)->flush();
        $this->app->make(EventRecorder::class)->flush();

        $this->assertSame(0, Event::query()->count());
        $this->assertSame(0, Metric::query()->where('app_id', InternalApp::ID)->count());
    }

    public function test_it_is_never_sampled_even_beside_your_applications(): void
    {
        App::factory()->count(2)->create();

        $this->app->make(Reloader::class)->reloadAll();
        $this->app->make(MetricsRecorder::class)->flush();

        $this->assertSame(2, Metric::query()->count());
        $this->assertSame(0, Metric::query()->where('app_id', InternalApp::ID)->count());
    }
}
