<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Metrics\EventRecorder;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Event;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Reloader;
use RobertBoes\Patchbay\Tests\Fixtures\FakeConnection;
use RobertBoes\Patchbay\Tests\TestCase;

class EventRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function recorder(): EventRecorder
    {
        return $this->app->make(EventRecorder::class);
    }

    protected function load(App $app): \Laravel\Reverb\Application
    {
        $application = $this->app->make(AppSource::class)->loadById($app->id);

        $this->app->make(Registry::class)->put($application);

        return $application;
    }

    public function test_it_records_what_an_event_was_and_where_it_went(): void
    {
        $application = $this->load(App::create(['name' => 'one']));

        $this->recorder()->sent(
            $application,
            '{"event":"OrderShipped","channel":"orders","data":"{\"id\":7}"}',
        );

        $this->assertSame(1, $this->recorder()->flush());

        $event = Event::query()->firstOrFail();
        $this->assertSame($application->id(), $event->app_id);
        $this->assertSame(Event::SENT, $event->direction);
        $this->assertTrue($event->wasSent());
        $this->assertSame('OrderShipped', $event->event);
        $this->assertSame('orders', $event->channel);
        $this->assertSame('{"id":7}', $event->payload);
    }

    public function test_a_received_event_is_recorded_as_received(): void
    {
        $application = $this->load(App::create(['name' => 'one']));

        $this->recorder()->received($application, '{"event":"client-typing","channel":"chat"}');
        $this->recorder()->flush();

        $this->assertSame(Event::RECEIVED, Event::query()->firstOrFail()->direction);
    }

    public function test_nothing_is_written_when_nothing_happened(): void
    {
        $this->assertSame(0, $this->recorder()->flush());
        $this->assertSame(0, Event::query()->count());
    }

    public function test_a_message_it_cannot_read_is_not_recorded(): void
    {
        $application = $this->load(App::create(['name' => 'one']));

        $this->recorder()->sent($application, 'not json');
        $this->recorder()->sent($application, '{"data":{}}');

        $this->assertSame(0, $this->recorder()->flush());
    }

    public function test_payloads_are_cut_to_the_configured_length(): void
    {
        config()->set('patchbay.events.payload_length', 10);

        $application = $this->load(App::create(['name' => 'one']));

        $this->recorder()->sent($application, '{"event":"Long","data":"' . str_repeat('x', 50) . '"}');
        $this->recorder()->flush();

        $this->assertSame(str_repeat('x', 10), Event::query()->firstOrFail()->payload);
    }

    public function test_payloads_can_be_kept_out_entirely(): void
    {
        config()->set('patchbay.events.payloads', false);

        $application = $this->load(App::create(['name' => 'one']));

        $this->recorder()->sent($application, '{"event":"Secret","data":"sensitive"}');
        $this->recorder()->flush();

        $event = Event::query()->firstOrFail();
        $this->assertSame('Secret', $event->event);
        $this->assertNull($event->payload);
    }

    public function test_a_burst_past_the_buffer_drops_the_oldest_rather_than_growing(): void
    {
        config()->set('patchbay.events.buffer', 3);

        $application = $this->load(App::create(['name' => 'one']));

        foreach (range(1, 6) as $number) {
            $this->recorder()->sent($application, '{"event":"E' . $number . '"}');
        }

        $this->assertSame(3, $this->recorder()->flush());
        $this->assertSame(['E4', 'E5', 'E6'], Event::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_the_log_records_what_the_listeners_pass_it(): void
    {
        $application = $this->load(App::create(['name' => 'one']));
        $connection = new FakeConnection($application);

        $this->app->make(Reloader::class)->start();

        $events = $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class);
        $events->dispatch(new MessageSent($connection, '{"event":"OrderShipped","channel":"orders"}'));
        $events->dispatch(new MessageReceived($connection, '{"event":"client-typing","channel":"chat"}'));

        // Protocol noise belongs in neither the counts nor the log.
        $events->dispatch(new MessageSent($connection, '{"event":"pusher:ping","data":{}}'));
        $events->dispatch(new MessageReceived($connection, '{"event":"pusher:subscribe","data":{"channel":"orders"}}'));

        $this->recorder()->flush();

        $this->assertSame(['OrderShipped', 'client-typing'], Event::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_buffered_events_are_written_before_the_panel_is_told_to_re_read(): void
    {
        $application = $this->load(App::create(['name' => 'one']));
        $connection = new FakeConnection($application);

        $this->app->make(Reloader::class)->start();

        $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
            ->dispatch(new MessageSent($connection, '{"event":"OrderShipped","channel":"orders"}'));

        // Nothing is written until a flush, which is what makes the ordering
        // matter: told to re-read first, the panel finds nothing and is never
        // told again.
        $this->assertSame(0, Event::query()->count());

        $this->runTimersOnce();

        $this->assertSame(1, Event::query()->count());
    }

    /**
     * Run whatever the reloader put on the loop, once.
     */
    protected function runTimersOnce(): void
    {
        $this->loop->addTimer(3, fn() => $this->loop->stop());
        $this->loop->run();
    }

    public function test_the_log_can_be_turned_off(): void
    {
        config()->set('patchbay.events.enabled', false);

        $application = $this->load(App::create(['name' => 'one']));
        $connection = new FakeConnection($application);

        $this->app->make(Reloader::class)->start();

        $this->app->make(\Illuminate\Contracts\Events\Dispatcher::class)
            ->dispatch(new MessageSent($connection, '{"event":"OrderShipped"}'));

        $this->recorder()->flush();

        $this->assertSame(0, Event::query()->count());
    }
}
