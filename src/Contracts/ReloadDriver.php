<?php

namespace RobertBoes\Patchbay\Contracts;

use RobertBoes\Patchbay\Reload\AppChange;

/**
 * How a change made in one process reaches the Reverb server in another. The
 * control panel publishes; the running server listens. Carrying the ID of what
 * changed lets the server reload one application instead of all of them.
 */
interface ReloadDriver
{
    /**
     * Called from whichever process made the change, usually a web request.
     */
    public function publish(AppChange $change): void;

    /**
     * Called once inside the Reverb server process. Implementations must not
     * block: the server runs a single event loop, and anything that blocks it
     * stalls every connection.
     *
     * @param  callable(AppChange): void  $onChange
     * @param  callable(): void  $onDesync  Called when changes were missed and
     *                                      the listener should reload everything.
     */
    public function listen(callable $onChange, callable $onDesync): void;

    public function stopListening(): void;
}
