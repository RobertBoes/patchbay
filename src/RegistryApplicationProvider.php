<?php

namespace RobertBoes\Patchbay;

use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;
use RobertBoes\Patchbay\Contracts\AppSource;

class RegistryApplicationProvider implements ApplicationProvider
{
    public function __construct(
        protected Registry $registry,
        protected AppSource $source,
    ) {
        //
    }

    /**
     * @return Collection<int, Application>
     */
    public function all(): Collection
    {
        if (! $this->registry->isComplete()) {
            $this->registry->replace($this->source->load());
        }

        return $this->registry->all();
    }

    /**
     * @throws InvalidApplication
     */
    public function findById(string $id): Application
    {
        return $this->registry->findById($id)
            ?? $this->loadOrFail(fn() => $this->source->loadById($id));
    }

    /**
     * @throws InvalidApplication
     */
    public function findByKey(string $key): Application
    {
        return $this->registry->findByKey($key)
            ?? $this->loadOrFail(fn() => $this->source->loadByKey($key));
    }

    /**
     * @param  callable(): ?Application  $load
     *
     * @throws InvalidApplication
     */
    protected function loadOrFail(callable $load): Application
    {
        if ($this->registry->isComplete()) {
            throw new InvalidApplication();
        }

        if (! $application = $load()) {
            throw new InvalidApplication();
        }

        $this->registry->put($application);

        return $application;
    }
}
