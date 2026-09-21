<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Container\Container;
use Laravel\Reverb\Application;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;

/**
 * Disconnects the clients of a revoked application. Forgetting an application
 * only stops new connections; open ones were authenticated when established.
 *
 * Reverb tracks connections through channels, so a client that has connected
 * but never subscribed is not enumerable and survives until its timeout.
 */
class ConnectionTerminator
{
    public function __construct(protected Container $container)
    {
        //
    }

    public function terminate(Application $application): int
    {
        // Only bound inside the running server, where the Pusher router built it.
        if (! $this->container->bound(ChannelManager::class)) {
            return 0;
        }

        $connections = $this->container->make(ChannelManager::class)
            ->for($application)
            ->connections();

        foreach ($connections as $connection) {
            $connection->disconnect();
        }

        return count($connections);
    }
}
