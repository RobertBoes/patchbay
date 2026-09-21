<?php

namespace RobertBoes\Patchbay\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Reverb\Application;
use RobertBoes\Patchbay\ApplicationFactory;
use RobertBoes\Patchbay\Contracts\AppSource;

/**
 * Loads applications from the database. Nothing here runs on the connection
 * path: everything is loaded at boot or when a change is missed, and a single
 * application is an indexed lookup.
 */
class EloquentAppSource implements AppSource
{
    public function __construct(
        protected ApplicationFactory $factory,
        /** @var class-string<Model> */
        protected string $model,
    ) {
        //
    }

    /**
     * @return Collection<int, Application>
     */
    public function load(): Collection
    {
        return $this->query()
            ->get()
            ->map(fn(Model $app) => $this->toApplication($app))
            ->values();
    }

    public function loadById(string $id): ?Application
    {
        $app = $this->query()->find($id);

        return $app ? $this->toApplication($app) : null;
    }

    public function loadByKey(string $key): ?Application
    {
        $app = $this->query()->where('key', $key)->first();

        return $app ? $this->toApplication($app) : null;
    }

    /**
     * A plain where rather than a scope, so a model swapped in through
     * `patchbay.model` only has to have the column, not implement our API.
     */
    protected function query(): Builder
    {
        return $this->model::query()->where('active', true);
    }

    protected function toApplication(Model $app): Application
    {
        // The model hides the secret; the server needs it to sign requests.
        return $this->factory->make(
            $app->makeVisible('secret')->attributesToArray(),
        );
    }
}
