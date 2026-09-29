<?php

namespace RobertBoes\Patchbay\Metrics;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Laravel\Reverb\Application;
use Laravel\Reverb\Loggers\Log;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use RobertBoes\Patchbay\Internal\InternalApp;
use RobertBoes\Patchbay\Registry;

class MetricsRecorder
{
    /** @var array<string, array{sent: int, received: int}> */
    protected array $messages = [];

    /**
     * The same counts, kept since the server started rather than emptied at
     * every flush. A sample answers what a minute carried; this answers what
     * the server has carried, which is what a live figure on screen means.
     *
     * @var array<string, array{sent: int, received: int}>
     */
    protected array $totals = [];

    public function __construct(
        protected Container $container,
        protected Registry $registry,
        protected string $model,
        protected InternalApp $internal,
        protected ?string $server = null,
    ) {
        //
    }

    public function messageSent(Application $application): void
    {
        $this->count($application->id(), 'sent');
    }

    public function messageReceived(Application $application): void
    {
        $this->count($application->id(), 'received');
    }

    protected function count(string $appId, string $direction): void
    {
        $this->messages[$appId] ??= ['sent' => 0, 'received' => 0];
        $this->messages[$appId][$direction]++;

        $this->totals[$appId] ??= ['sent' => 0, 'received' => 0];
        $this->totals[$appId][$direction]++;
    }

    /**
     * @return array<string, array{sent: int, received: int}>
     */
    public function totals(): array
    {
        return $this->totals;
    }

    public function flush(?Carbon $at = null): int
    {
        $at ??= Carbon::now();
        $channels = $this->channelManager();

        // The panel's own application is how these figures reach a screen,
        // not traffic an application carried, so it is not sampled.
        $rows = $this->registry->all()
            ->reject(fn(Application $application) => $this->internal->is($application))
            ->map(fn(Application $application) => $this->sample($application, $channels, $at))
            ->values()
            ->all();

        $this->messages = [];

        if ($rows === []) {
            return 0;
        }

        $this->model::query()->insert($rows);

        Log::info('Patchbay Metrics Recorded', (string) count($rows));

        return count($rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sample(Application $application, ?ChannelManager $channels, Carbon $at): array
    {
        $counters = $this->messages[$application->id()] ?? ['sent' => 0, 'received' => 0];
        $channel = $channels?->for($application);

        return [
            'app_id' => $application->id(),
            'server' => $this->server,
            'connections' => count($channel?->connections() ?? []),
            'channels' => count($channel?->all() ?? []),
            'messages_sent' => $counters['sent'],
            'messages_received' => $counters['received'],
            'recorded_at' => $at,
        ];
    }

    /**
     * @return array<string, array{sent: int, received: int}>
     */
    public function pending(): array
    {
        return $this->messages;
    }

    protected function channelManager(): ?ChannelManager
    {
        if (! $this->container->bound(ChannelManager::class)) {
            return null;
        }

        return $this->container->make(ChannelManager::class);
    }
}
