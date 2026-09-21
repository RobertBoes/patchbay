<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Events\MessageSent;
use Laravel\Reverb\Loggers\Log;
use React\EventLoop\Loop;
use RobertBoes\Patchbay\Metrics\MetricsRecorder;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Contracts\ReloadDriver;
use RobertBoes\Patchbay\Reload\AppChange;

class Reloader
{
    protected bool $listening = false;

    public function __construct(
        protected Registry $registry,
        protected AppSource $source,
        protected ReloadDriver $driver,
        protected ConnectionTerminator $terminator,
        protected Container $container,
        protected Config $config,
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

        $this->driver->listen(
            fn(AppChange $change) => $this->apply($change),
            fn() => $this->reloadAll(),
        );

        $this->listening = true;
    }

    protected function recordMetrics(): void
    {
        if (! $this->config->get('patchbay.metrics.enabled', true)) {
            return;
        }

        $recorder = $this->container->make(MetricsRecorder::class);
        $events = $this->container->make(Dispatcher::class);

        $events->listen(
            MessageSent::class,
            fn(MessageSent $event) => $recorder->messageSent($event->connection->app()),
        );

        $events->listen(
            MessageReceived::class,
            fn(MessageReceived $event) => $recorder->messageReceived($event->connection->app()),
        );

        Loop::get()->addPeriodicTimer(
            max(1, (int) $this->config->get('patchbay.metrics.interval', 60)),
            fn() => $recorder->flush(),
        );
    }

    public function stop(): void
    {
        $this->driver->stopListening();

        $this->listening = false;
    }

    public function reloadAll(): void
    {
        $this->registry->replace($this->source->load());

        Log::info('Patchbay Applications Loaded', (string) $this->registry->count());
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
