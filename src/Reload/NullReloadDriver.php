<?php

namespace RobertBoes\Patchbay\Reload;

use RobertBoes\Patchbay\Contracts\ReloadDriver;

/**
 * Does nothing: applications are read at boot and never refreshed. Right when
 * they only change at deploy time, and in tests.
 */
class NullReloadDriver implements ReloadDriver
{
    public function publish(AppChange $change): void
    {
        //
    }

    public function listen(callable $onChange, callable $onDesync): void
    {
        //
    }

    public function stopListening(): void
    {
        //
    }
}
