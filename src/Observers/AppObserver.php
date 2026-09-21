<?php

namespace RobertBoes\Patchbay\Observers;

use RobertBoes\Patchbay\Contracts\ReloadDriver;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Reload\AppChange;

/**
 * Mints credentials, and tells the running server what changed.
 */
class AppObserver
{
    /**
     * Attributes the Reverb server reads. Renaming an application is a control
     * panel concern and never reaches the event loop.
     */
    protected const RELOADABLE = [
        'key',
        'secret',
        'active',
        'ping_interval',
        'activity_timeout',
        'allowed_origins',
        'max_message_size',
        'max_connections',
        'accept_client_events_from',
        'rate_limiting',
    ];

    public function __construct(protected ReloadDriver $driver)
    {
        //
    }

    public function creating(App $app): void
    {
        // Off the instance, so a model extending this one can override minting.
        $app->key ??= $app::generateKey();
        $app->secret ??= $app::generateSecret();
    }

    public function created(App $app): void
    {
        if ($app->active) {
            $this->publish(AppChange::upsert($app->id));
        }
    }

    public function updated(App $app): void
    {
        if (! $app->wasChanged(self::RELOADABLE)) {
            return;
        }

        $this->publish($app->active
            ? AppChange::upsert($app->id)
            : AppChange::delete($app->id));
    }

    public function deleted(App $app): void
    {
        $this->publish(AppChange::delete($app->id));
    }

    public function restored(App $app): void
    {
        if ($app->active) {
            $this->publish(AppChange::upsert($app->id));
        }
    }

    protected function publish(AppChange $change): void
    {
        $this->driver->publish($change);
    }
}
