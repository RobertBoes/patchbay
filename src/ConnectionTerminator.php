<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Container\Container;
use Laravel\Reverb\Application;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;

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
