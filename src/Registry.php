<?php

namespace RobertBoes\Patchbay;

use Illuminate\Support\Collection;
use Laravel\Reverb\Application;

/**
 * The in-memory set of applications the server answers from.
 *
 * Reverb's ApplicationProvider returns an Application, not a promise, so a
 * lookup cannot await I/O — anything synchronous there blocks the event loop
 * and every connection on it. This keeps the lookup an array read, and a reload
 * driver keeps the contents current.
 */
class Registry
{
    /** @var array<string, Application> */
    protected array $byKey = [];

    /** @var array<string, Application> */
    protected array $byId = [];

    /**
     * Whether a miss can be answered authoritatively instead of falling back.
     */
    protected bool $complete = false;

    public function put(Application $application): void
    {
        $this->forgetById($application->id());

        $this->byKey[$application->key()] = $application;
        $this->byId[$application->id()] = $application;
    }

    public function findByKey(string $key): ?Application
    {
        return $this->byKey[$key] ?? null;
    }

    public function findById(string $id): ?Application
    {
        return $this->byId[$id] ?? null;
    }

    public function forgetById(string $id): void
    {
        if (! $existing = $this->byId[$id] ?? null) {
            return;
        }

        // A rotation leaves the old key pointing at a stale application, so
        // drop it by the key the registry actually stored it under.
        unset($this->byId[$id], $this->byKey[$existing->key()]);
    }

    /**
     * Replace the contents wholesale and mark the registry complete.
     *
     * @param  iterable<Application>  $applications
     */
    public function replace(iterable $applications): void
    {
        $this->flush();

        foreach ($applications as $application) {
            $this->put($application);
        }

        $this->complete = true;
    }

    /** @return Collection<int, Application> */
    public function all(): Collection
    {
        return collect(array_values($this->byId));
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function count(): int
    {
        return count($this->byId);
    }

    public function flush(): void
    {
        $this->byKey = [];
        $this->byId = [];
        $this->complete = false;
    }
}
