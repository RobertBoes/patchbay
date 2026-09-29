<?php

namespace RobertBoes\Patchbay\Contracts;

use RobertBoes\Patchbay\Reload\AppChange;

interface ReloadDriver
{
    public function publish(AppChange $change): void;

    /**
     * Must not block: the server runs a single event loop.
     *
     * @param  callable(AppChange): void  $onChange
     * @param  callable(): void  $onDesync  Called when changes were missed and
     *                                      the listener should reload everything.
     */
    public function listen(callable $onChange, callable $onDesync): void;

    public function stopListening(): void;
}
