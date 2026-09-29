<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Widgets\ServerStatus;
use RobertBoes\Patchbay\Server\Heartbeat;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

/**
 * Behind a proxy, as in Docker: the panel dials the server by an internal
 * name, clients by a public one.
 */
class ServerStatusAddressTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected bool $publiclyReachable = true;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('patchbay.server.url', 'http://patchbay-reverb:8080');
        config()->set('patchbay.server.cache_for', 0);
        config()->set('patchbay.options', ['host' => 'ws.example.com', 'port' => 443, 'scheme' => 'https']);

        Http::preventStrayRequests();
        Http::fake([
            'patchbay-reverb:8080/*' => Http::response('', 200),
            'ws.example.com/*' => fn() => Http::response('', $this->publiclyReachable ? 200 : 502),
        ]);

        app(Heartbeat::class)->beat(0);
    }

    public function test_it_shows_the_address_clients_use(): void
    {
        Livewire::test(ServerStatus::class)
            ->assertSee('Operational')
            ->assertSee('wss://ws.example.com')
            ->assertDontSee('patchbay-reverb');
    }

    public function test_it_says_so_when_the_public_address_does_not_answer(): void
    {
        $this->publiclyReachable = false;

        Livewire::test(ServerStatus::class)
            // Still operational: the server itself is fine, and a browser may
            // well reach a name the server cannot dial itself.
            ->assertSee('Operational')
            ->assertSee('wss://ws.example.com does not answer from here');
    }
}
