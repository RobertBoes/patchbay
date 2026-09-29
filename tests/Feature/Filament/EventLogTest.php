<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Widgets\EventLog;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Event;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class EventLogTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function event(
        App $app,
        string $name,
        string $direction = Event::SENT,
        ?string $channel = null,
        ?string $payload = null,
        ?Carbon $at = null,
    ): void {
        Event::create([
            'app_id' => $app->id,
            'direction' => $direction,
            'event' => $name,
            'channel' => $channel,
            'payload' => $payload,
            'recorded_at' => $at ?? Carbon::now(),
        ]);
    }

    public function test_it_lists_what_the_application_carried(): void
    {
        $app = App::factory()->create();

        $this->event($app, 'OrderShipped', channel: 'orders', payload: '{"id":7}');

        Livewire::test(EventLog::class, ['record' => $app])
            ->assertOk()
            ->assertSee('OrderShipped')
            ->assertSee('orders')
            ->assertSee('Sent');
    }

    public function test_it_shows_only_the_application_being_viewed(): void
    {
        $app = App::factory()->create();
        $other = App::factory()->create();

        $this->event($app, 'Mine');
        $this->event($other, 'Theirs');

        Livewire::test(EventLog::class, ['record' => $app])
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    public function test_the_newest_is_listed_first(): void
    {
        $app = App::factory()->create();

        $this->event($app, 'Older', at: Carbon::now()->subMinutes(10));
        $this->event($app, 'Newer', at: Carbon::now());

        Livewire::test(EventLog::class, ['record' => $app])
            ->assertSeeInOrder(['Newer', 'Older']);
    }

    public function test_a_received_event_reads_as_received(): void
    {
        $app = App::factory()->create();

        $this->event($app, 'client-typing', direction: Event::RECEIVED, channel: 'chat');

        Livewire::test(EventLog::class, ['record' => $app])->assertSee('Received');
    }

    public function test_an_application_that_has_carried_nothing_says_so(): void
    {
        $app = App::factory()->create();

        Livewire::test(EventLog::class, ['record' => $app])->assertSee('Nothing carried yet');
    }

    public function test_it_re_reads_when_the_server_reports_a_change(): void
    {
        $app = App::factory()->create();

        $widget = Livewire::test(EventLog::class, ['record' => $app])
            ->assertDontSee('ArrivedLater');

        // Written after the widget rendered, the way a real one would be.
        $this->event($app, 'ArrivedLater');

        $widget->dispatch('patchbay-stats-changed')->assertSee('ArrivedLater');
    }

    public function test_it_polls_on_the_interval_a_table_widget_reads(): void
    {
        // A table widget never reads getPollingInterval, so an interval put
        // there would leave it never refreshing on its own.
        config()->set('patchbay.metrics.interval', 9);

        $widget = Livewire::test(EventLog::class, ['record' => App::factory()->create()])->instance();

        $this->assertSame('9s', $widget->getTablePollingInterval());
    }

    public function test_the_widget_is_hidden_when_the_log_is_turned_off(): void
    {
        config()->set('patchbay.events.enabled', false);

        $this->assertFalse(EventLog::canView());
    }
}
