<?php

namespace RobertBoes\Patchbay\Contracts;

use Illuminate\Support\Collection;
use Laravel\Reverb\Application;

/**
 * Where the registry loads applications from. A source owns the data and may do
 * real I/O: it is read at boot and when an application changes, never on the
 * connection path.
 *
 * Absence is returned rather than thrown, because the registry has to tell
 * "not loaded yet" apart from "does not exist".
 */
interface AppSource
{
    /**
     * @return Collection<int, Application>
     */
    public function load(): Collection;

    public function loadById(string $id): ?Application;

    public function loadByKey(string $key): ?Application;
}
