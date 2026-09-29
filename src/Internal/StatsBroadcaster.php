<?php

namespace RobertBoes\Patchbay\Internal;

use Illuminate\Contracts\Container\Container;
use Laravel\Reverb\Application;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use RobertBoes\Patchbay\Metrics\MetricsRecorder;
use RobertBoes\Patchbay\Registry;

/**
 * Pushes what the server is holding to the panel, so figures on screen follow
 * the server rather than a timer.
 *
 * This reads the same things the panel would otherwise ask for over HTTP, and
 * sends them to the one channel the internal application carries. Nothing is
 * stored: it is a reading, not a sample, and the metrics table remains what
 * charts and history are drawn from.
 */
class StatsBroadcaster
{
    public const EVENT = 'patchbay:stats';

    /** The last reading sent, so an unchanged one is not sent again. */
    protected ?string $last = null;

    public function __construct(
        protected Container $container,
        protected Registry $registry,
        protected InternalApp $internal,
        protected MetricsRecorder $recorder,
    ) {
        //
    }

    /**
     * @return bool  Whether anything was listening to send it to.
     */
    public function publish(): bool
    {
        if (! $this->internal->enabled()) {
            return false;
        }

        // Only bound inside the running server, where the Pusher router built
        // it. Nothing to push from anywhere else.
        if (! $this->container->bound(ChannelManager::class)) {
            return false;
        }

        $channels = $this->container->make(ChannelManager::class);
        $channel = $channels->for($this->internal->application())->find(InternalApp::CHANNEL);

        // Nobody is watching, so there is nothing to tell.
        if (! $channel || $channel->connections() === []) {
            return false;
        }

        $reading = $this->reading($channels);

        // Timestamps change on every tick, so what is compared is the reading
        // itself: an idle server pushes nothing, and a panel left open costs
        // no refreshes until something actually moves.
        $fingerprint = md5((string) json_encode($reading['apps']));

        if ($fingerprint === $this->last) {
            return false;
        }

        $this->last = $fingerprint;

        $channel->broadcastToAll([
            'event' => self::EVENT,
            'channel' => InternalApp::CHANNEL,
            'data' => (string) json_encode($reading),
        ]);

        return true;
    }

    /**
     * @return array{at: int, apps: array<int, array<string, mixed>>}
     */
    protected function reading(ChannelManager $channels): array
    {
        $totals = $this->recorder->totals();

        $apps = $this->registry->all()
            ->reject(fn(Application $application) => $this->internal->is($application))
            ->map(fn(Application $application) => $this->forApplication($application, $channels, $totals))
            ->values()
            ->all();

        return ['at' => time(), 'apps' => $apps];
    }

    /**
     * @param  array<string, array{sent: int, received: int}>  $totals
     * @return array<string, mixed>
     */
    protected function forApplication(Application $application, ChannelManager $channels, array $totals): array
    {
        $for = $channels->for($application);
        $open = $for->all();

        return [
            'id' => $application->id(),
            'connections' => count($for->connections()),
            'channels' => array_values(array_map(
                fn($channel) => [
                    'name' => $channel->name(),
                    'subscribers' => count($channel->connections()),
                    'presence' => str_starts_with($channel->name(), 'presence-'),
                ],
                array_filter($open, fn($channel) => count($channel->connections()) > 0),
            )),
            'messages' => $totals[$application->id()] ?? ['sent' => 0, 'received' => 0],
        ];
    }
}
