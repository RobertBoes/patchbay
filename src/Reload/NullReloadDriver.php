<?php

namespace RobertBoes\Patchbay\Reload;

use RobertBoes\Patchbay\Contracts\ReloadDriver;

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
