<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Reverb\Application;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Loggers\Log;
use React\EventLoop\LoopInterface;
use RobertBoes\Patchbay\Concerns\SurvivesFailures;
use RobertBoes\Patchbay\Internal\InternalApp;
use RobertBoes\Patchbay\Internal\StatsBroadcaster;
use RobertBoes\Patchbay\Metrics\EventRecorder;
use RobertBoes\Patchbay\Metrics\ProtocolMessage;
use RobertBoes\Patchbay\Metrics\MetricsRecorder;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Contracts\ReloadDriver;
use RobertBoes\Patchbay\Reload\AppChange;
use RobertBoes\Patchbay\Server\Heartbeat;

class Reloader
{
    use SurvivesFailures;

    protected bool $listening = false;

    public function __construct(
        protected Registry $registry,
        protected AppSource $source,
        protected ReloadDriver $driver,
        protected ConnectionTerminator $terminator,
        protected Container $container,
        protected LoopInterface $loop,
        protected Config $config,
        protected Heartbeat $heartbeat,
        protected InternalApp $internal,
        protected bool $terminateOnRevoke = true,
    ) {
        //
    }

    public function start(): void
    {
        if ($this->listening) {
            return;
        }

        $this->reloadAll();
        $this->recordMetrics();
        $this->pushStats();
        $this->beat();

        $this->driver->listen(
            fn(AppChange $change) => $this->survive('reload', fn() => $this->apply($change)),
            fn() => $this->survive('reload', fn() => $this->reloadAll()),
        );

        $this->listening = true;
    }

    protected function recordMetrics(): void
    {
        if (! $this->config->get('patchbay.metrics.enabled', true)) {
            return;
        }

        $recorder = $this->container->make(MetricsRecorder::class);
        $log = $this->eventRecorder();
        $events = $this->container->make(Dispatcher::class);

        // Connecting, subscribing and staying alive are what the protocol
        // costs, not traffic an application produced. Counting them would have
        // a connection that carried nothing still reporting a steady stream.
        $events->listen(
            MessageSent::class,
            function (MessageSent $event) use ($recorder, $log) {
                if ($this->isNotTraffic($event->connection->app(), $event->message)) {
                    return;
                }

                $recorder->messageSent($event->connection->app());
                $log?->sent($event->connection->app(), $event->message);
            },
        );

        $events->listen(
            MessageReceived::class,
            function (MessageReceived $event) use ($recorder, $log) {
                if ($this->isNotTraffic($event->connection->app(), $event->message)) {
                    return;
                }

                $recorder->messageReceived($event->connection->app());
                $log?->received($event->connection->app(), $event->message);
            },
        );

        $this->loop->addPeriodicTimer(
            max(1, (int) $this->config->get('patchbay.metrics.interval', 60)),
            function () use ($recorder, $log) {
                $this->survive('metrics', fn() => $recorder->flush());
                // Its own survive call: a failure writing the log must not
                // cost the sample that was taken beside it.
                $this->survive('events', fn() => $log?->flush());
            },
        );
    }

    /**
     * What an application carried, as opposed to what it cost to carry it.
     * The protocol running itself is not traffic, and neither is the panel
     * watching: reporting on figures would otherwise become part of them.
     */
    protected function isNotTraffic(Application $application, string $message): bool
    {
        return $this->internal->is($application) || ProtocolMessage::matches($message);
    }

    /**
     * A timer of its own rather than the metrics one: how often a reading is
     * pushed decides how fresh the panel looks, while the metrics interval
     * decides how much is stored. Tying them together would make a livelier
     * screen cost rows.
     */
    protected function pushStats(): void
    {
        if (! $this->internal->enabled()) {
            return;
        }

        $broadcaster = $this->container->make(StatsBroadcaster::class);

        $log = $this->eventRecorder();

        $this->loop->addPeriodicTimer(
            max(1, (int) $this->config->get('patchbay.internal.interval', 2)),
            fn() => $this->survive('stats', function () use ($broadcaster, $log) {
                // Events are buffered, and a message changes the reading the
                // moment it arrives. Pushing first would have the panel
                // re-read for rows that are still in the buffer, and nothing
                // would tell it to look again.
                $log?->flush();

                $broadcaster->publish();
            }),
        );
    }

    protected function eventRecorder(): ?EventRecorder
    {
        if (! $this->config->get('patchbay.events.enabled', true)) {
            return null;
        }

        return $this->container->make(EventRecorder::class);
    }

    protected function beat(): void
    {
        $beat = fn() => $this->survive('heartbeat', fn() => $this->heartbeat->beat($this->applicationCount()));

        $beat();

        $this->loop->addPeriodicTimer(Heartbeat::INTERVAL, $beat);
    }

    public function stop(): void
    {
        $this->driver->stopListening();

        $this->listening = false;
    }

    public function reloadAll(): void
    {
        $this->registry->replace($this->source->load());
        $this->registerInternalApp();

        Log::info('Patchbay Applications Loaded', (string) $this->applicationCount());
    }

    /**
     * The panel's own application, put back after every reload because
     * replacing the registry clears it. It is not loaded from anywhere, so
     * nothing else would.
     */
    protected function registerInternalApp(): void
    {
        if (! $this->internal->enabled()) {
            return;
        }

        $this->registry->put($this->internal->application());
    }

    /**
     * How many of your applications the server is holding. The panel's own
     * does not count: a server serving none of yours is degraded, and one
     * more application that is always there would hide that.
     */
    public function applicationCount(): int
    {
        $count = $this->registry->count();

        return $this->internal->enabled() ? max(0, $count - 1) : $count;
    }

    public function apply(AppChange $change): void
    {
        if ($change->isDelete()) {
            $this->remove($change->id);

            return;
        }

        // Deactivated or deleted since the signal was published.
        if (! $application = $this->source->loadById($change->id)) {
            $this->remove($change->id);

            return;
        }

        $this->registry->put($application);

        Log::info('Patchbay Application Reloaded', $change->id);
    }

    protected function remove(string $id): void
    {
        $application = $this->registry->findById($id);

        $this->registry->forgetById($id);

        Log::info('Patchbay Application Removed', $id);

        if (! $application || ! $this->terminateOnRevoke) {
            return;
        }

        if ($terminated = $this->terminator->terminate($application)) {
            Log::info('Patchbay Connections Terminated', "{$id} ({$terminated})");
        }
    }
}
