<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Reloader;
use RobertBoes\Patchbay\Server\Health;
use RobertBoes\Patchbay\Server\HealthCheck;
use RobertBoes\Patchbay\Server\Heartbeat;
use RobertBoes\Patchbay\Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $answering = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('patchbay.server.cache_for', 0);
        Http::preventStrayRequests();
        Http::fake(fn() => Http::response('', $this->answering ? 200 : 500));
    }

    protected function health(): Health
    {
        return $this->app->make(HealthCheck::class)->status();
    }

    protected function heartbeat(string $server = 'ws-1'): Heartbeat
    {
        return new Heartbeat($this->app['cache']->store(), $server);
    }

    public function test_a_server_that_does_not_answer_is_down(): void
    {
        $this->answering = false;

        $this->assertSame(Health::Down, $this->health());
    }

    public function test_a_server_that_answers_but_never_beats_is_degraded(): void
    {
        $this->assertSame(Health::Degraded, $this->health());
    }

    public function test_a_server_serving_nothing_while_applications_are_active_is_degraded(): void
    {
        App::factory()->create();
        $this->heartbeat()->beat(0);

        $this->assertSame(Health::Degraded, $this->health());
    }

    public function test_a_server_serving_its_applications_is_operational(): void
    {
        App::factory()->create();
        $this->heartbeat()->beat(1);

        $this->assertSame(Health::Operational, $this->health());
    }

    public function test_a_server_with_nothing_to_serve_is_operational(): void
    {
        App::factory()->inactive()->create();
        $this->heartbeat()->beat(0);

        $this->assertSame(Health::Operational, $this->health());
    }

    public function test_a_server_that_stopped_beating_counts_as_gone(): void
    {
        $this->heartbeat()->beat(1);

        $this->travel(Heartbeat::INTERVAL * 3 + 1)->seconds();

        $this->assertSame([], $this->heartbeat()->live());
        $this->assertSame(Health::Degraded, $this->health());
    }

    public function test_each_server_keeps_its_own_beat(): void
    {
        $this->heartbeat('ws-1')->beat(2);
        $this->heartbeat('ws-2')->beat(3);

        $this->assertSame(['ws-1', 'ws-2'], array_keys($this->heartbeat()->live()));
    }

    public function test_the_server_beats_what_it_loaded_as_it_starts(): void
    {
        App::factory()->count(2)->create();

        $this->app->make(Reloader::class)->start();

        $this->assertSame(2, collect($this->heartbeat()->live())->sole()['applications']);
    }
}
