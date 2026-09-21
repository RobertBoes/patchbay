<?php

namespace RobertBoes\Patchbay\Metrics;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Laravel\Reverb\Application;
use Laravel\Reverb\Loggers\Log;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use RobertBoes\Patchbay\Registry;

/**
 * Samples what each application is doing, from inside the running server. The
 * counts are already in memory here, and message throughput exists nowhere
 * else — the HTTP API does not report it.
 *
 * Messages are counted in memory and written once per interval, so the database
 * never sits on the path of an individual frame.
 */
class MetricsRecorder
{
    /** @var array<string, array{sent: int, received: int}> */
    protected array $messages = [];

    public function __construct(
        protected Container $container,
        protected Registry $registry,
        protected string $model,
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
    }

    public function flush(?Carbon $at = null): int
    {
        $at ??= Carbon::now();
        $channels = $this->channelManager();

        $rows = $this->registry->all()
            ->map(fn(Application $application) => $this->sample($application, $channels, $at))
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

    /**
     * Null outside the running server, where there is nothing to sample.
     */
    protected function channelManager(): ?ChannelManager
    {
        if (! $this->container->bound(ChannelManager::class)) {
            return null;
        }

        return $this->container->make(ChannelManager::class);
    }
}
