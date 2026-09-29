<?php

namespace RobertBoes\Patchbay\Server;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * What each running server says it is serving, written from inside the
 * server. The HTTP API only proves the process answers; a server can do
 * that while holding no applications at all, refusing every connection.
 */
class Heartbeat
{
    /** Seconds between beats. Three missed beats and a server counts as gone. */
    public const INTERVAL = 10;

    protected const KEY = 'patchbay:heartbeat';

    public function __construct(
        protected Cache $cache,
        protected string $server,
    ) {
        //
    }

    /**
     * ponytail: read-modify-write on one key. Two servers beating in the same
     * instant can drop one entry until its next beat; per-server keys plus an
     * index if a fleet ever needs better.
     */
    public function beat(int $applications): void
    {
        $this->cache->forever(self::KEY, [
            ...$this->live(),
            $this->server => ['applications' => $applications, 'at' => now()->getTimestamp()],
        ]);
    }

    /**
     * Servers that have beaten recently, keyed by name.
     *
     * @return array<string, array{applications: int, at: int}>
     */
    public function live(): array
    {
        $cutoff = now()->getTimestamp() - self::INTERVAL * 3;

        return array_filter(
            (array) $this->cache->get(self::KEY, []),
            fn(array $beat) => $beat['at'] >= $cutoff,
        );
    }
}
