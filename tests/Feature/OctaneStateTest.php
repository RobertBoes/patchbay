<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Reverb\Application;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Tests\TestCase;

/**
 * Under Octane the container survives between requests, so a singleton holding
 * state carries it into the next request. A registry filled during one request
 * would keep answering with applications that have since changed, and nothing
 * in an ordinary test suite would notice because every test boots fresh.
 */
class OctaneStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registry_is_dropped_between_octane_requests(): void
    {
        $registry = $this->app->make(Registry::class);

        $registry->put(new Application(
            id: 'id-1',
            key: 'key-1',
            secret: 'secret',
            pingInterval: 60,
            activityTimeout: 30,
            allowedOrigins: ['*'],
            maxMessageSize: 10_000,
        ));

        $this->assertSame(1, $registry->count());

        $this->app->make('events')->dispatch(new RequestReceived($this->app, $this->app, $this->app['request']));

        $this->assertSame(0, $registry->count(), 'A new Octane request must start from a clean registry.');
    }
}
