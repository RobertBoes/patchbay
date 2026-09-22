<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Widgets\DebugConsole;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class DebugConsoleTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('{}', 200)]);
    }

    protected function notified(string $title): callable
    {
        return fn(string $event, array $params) => $params['notification']['title'] === $title;
    }

    public function test_it_sends_an_event_to_the_channel(): void
    {
        $app = App::factory()->create();

        Livewire::test(DebugConsole::class, ['record' => $app])
            ->set('channel', 'orders')
            ->set('event', 'shipped')
            ->set('payload', '{"id": 7}')
            ->call('send')
            ->assertHasNoErrors()
            ->assertDispatched('notificationSent', $this->notified('Sent shipped to orders.'));

        Http::assertSent(fn(Request $request) => str_contains($request->url(), "/apps/{$app->id}/events")
            && $request->data() === ['name' => 'shipped', 'channels' => ['orders'], 'data' => '{"id":7}']);
    }

    public function test_the_payload_must_be_json_structure(): void
    {
        $app = App::factory()->create();

        Livewire::test(DebugConsole::class, ['record' => $app])
            ->set('payload', 'not json')
            ->call('send')
            ->assertHasErrors(['payload' => 'json']);

        Livewire::test(DebugConsole::class, ['record' => $app])
            ->set('payload', '42')
            ->call('send')
            ->assertHasErrors('payload');

        Http::assertNothingSent();
    }

    public function test_a_payload_over_pushers_limit_is_refused(): void
    {
        Livewire::test(DebugConsole::class, ['record' => App::factory()->create()])
            ->set('payload', json_encode(['blob' => str_repeat('x', 11_000)]))
            ->call('send')
            ->assertHasErrors(['payload' => 'max']);

        Http::assertNothingSent();
    }

    public function test_a_channel_name_outside_pushers_rules_is_refused(): void
    {
        Livewire::test(DebugConsole::class, ['record' => App::factory()->create()])
            ->set('channel', 'has spaces')
            ->call('send')
            ->assertHasErrors(['channel' => 'regex']);
    }

    public function test_sending_is_rate_limited(): void
    {
        $app = App::factory()->create();
        $console = Livewire::test(DebugConsole::class, ['record' => $app]);

        for ($i = 0; $i < DebugConsole::SENDS_PER_MINUTE; $i++) {
            $console->call('send');
        }

        $console->call('send')
            ->assertDispatched('notificationSent', fn(string $event, array $params) => str_starts_with($params['notification']['title'], 'Slow down'));

        Http::assertSentCount(DebugConsole::SENDS_PER_MINUTE);
    }

    public function test_an_inactive_application_sends_nothing(): void
    {
        $app = App::factory()->inactive()->create();

        Livewire::test(DebugConsole::class, ['record' => $app])
            ->assertSee('Activate the application to use the console.')
            ->call('send')
            ->assertDispatched('notificationSent', $this->notified('Activate the application to send events.'));

        Http::assertNothingSent();
    }
}
