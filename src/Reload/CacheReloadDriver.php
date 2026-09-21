<?php

namespace RobertBoes\Patchbay\Reload;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use RobertBoes\Patchbay\Contracts\ReloadDriver;

class CacheReloadDriver implements ReloadDriver
{
    protected ?TimerInterface $timer = null;

    protected ?TimerInterface $reconcileTimer = null;

    protected int $seen = 0;

    protected Cache $cache;

    protected string $prefix;

    protected int $interval;

    protected int $backlog;

    protected ?int $reconcileEvery;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(CacheFactory $cache, array $config = [])
    {
        $this->cache = $cache->store($config['store'] ?? null);
        $this->prefix = $config['prefix'] ?? 'patchbay';
        $this->interval = (int) ($config['interval'] ?? 5);
        $this->backlog = (int) ($config['backlog'] ?? 100);
        $this->reconcileEvery = $config['reconcile_every'] ?? 300;
    }

    /**
     * Concurrent publishes can interleave and lose a log entry. The version
     * counter still counts both, so the server sees the mismatch and reloads.
     */
    public function publish(AppChange $change): void
    {
        $version = $this->cache->increment($this->versionKey());

        // A just-cleared store returns false or null, so seed the counter.
        if (! is_int($version)) {
            $this->cache->forever($this->versionKey(), $version = 1);
        }

        $log = $this->log();
        $log[] = $change->withVersion($version)->toArray();

        $this->cache->forever(
            $this->logKey(),
            array_slice($log, -$this->backlog),
        );
    }

    public function listen(callable $onChange, callable $onDesync): void
    {
        $this->seen = $this->version();

        $loop = Loop::get();

        $this->timer = $loop->addPeriodicTimer(
            $this->interval,
            fn() => $this->drain($onChange, $onDesync),
        );

        if ($this->reconcileEvery !== null) {
            $this->reconcileTimer = $loop->addPeriodicTimer(
                $this->reconcileEvery,
                function () use ($onDesync) {
                    $this->seen = $this->version();
                    $onDesync();
                },
            );
        }
    }

    /**
     * @param  callable(AppChange): void  $onChange
     * @param  callable(): void  $onDesync
     */
    public function drain(callable $onChange, callable $onDesync): void
    {
        if (($version = $this->version()) === $this->seen) {
            return;
        }

        $seen = $this->seen;
        $this->seen = $version;

        $changes = array_values(array_filter(
            $this->log(),
            fn(array $entry) => ((int) ($entry['version'] ?? 0)) > $seen,
        ));

        // One entry per version, or entries fell off the backlog and replaying
        // what is left would leave the registry holding dead applications.
        if (count($changes) !== $version - $seen) {
            $onDesync();

            return;
        }

        foreach ($changes as $entry) {
            $onChange(AppChange::fromArray($entry));
        }
    }

    public function stopListening(): void
    {
        $loop = Loop::get();

        if ($this->timer) {
            $loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        if ($this->reconcileTimer) {
            $loop->cancelTimer($this->reconcileTimer);
            $this->reconcileTimer = null;
        }
    }

    public function version(): int
    {
        return (int) $this->cache->get($this->versionKey(), 0);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function log(): array
    {
        return (array) $this->cache->get($this->logKey(), []);
    }

    protected function versionKey(): string
    {
        return "{$this->prefix}:version";
    }

    protected function logKey(): string
    {
        return "{$this->prefix}:changes";
    }
}
