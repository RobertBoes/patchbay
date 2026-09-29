<?php

namespace RobertBoes\Patchbay\Contracts;

use Illuminate\Support\Collection;
use Laravel\Reverb\Application;

interface AppSource
{
    /**
     * @return Collection<int, Application>
     */
    public function load(): Collection;

    public function loadById(string $id): ?Application;

    public function loadByKey(string $key): ?Application;
}
