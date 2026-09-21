<?php

use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Reload\CacheReloadDriver;
use RobertBoes\Patchbay\Reload\NullReloadDriver;
use RobertBoes\Patchbay\Sources\EloquentAppSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Source
    |--------------------------------------------------------------------------
    |
    | Where Patchbay loads your Reverb applications from. The default loads
    | them from the database with Eloquent, which is the source of truth.
    | Point this at your own implementation of the AppSource contract to
    | load them from somewhere else, such as an internal HTTP API.
    |
    */

    'source' => EloquentAppSource::class,

    'model' => App::class,

    'table' => 'patchbay_apps',

    'connection' => env('PATCHBAY_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Reload Driver
    |--------------------------------------------------------------------------
    |
    | How the running Reverb server learns that an application changed. The
    | server keeps applications in memory, so it needs to be told when to
    | refresh. The "cache" driver needs no extra infrastructure at all.
    |
    | Supported: "cache", "null"
    |
    */

    'reload' => [

        'driver' => env('PATCHBAY_RELOAD_DRIVER', 'cache'),

        'drivers' => [

            'cache' => [
                'via' => CacheReloadDriver::class,

                // Cache store used to pass change signals to the server.
                // Must be shared between the control panel and the server,
                // so "array" and "file" will not work across processes.
                'store' => env('PATCHBAY_CACHE_STORE'),

                // How often the server checks for changes, in seconds. Each
                // check is a single cache read; applications are only
                // re-read when the version actually moved.
                'interval' => env('PATCHBAY_RELOAD_INTERVAL', 5),

                // How many recent changes to keep. If the server falls
                // further behind than this it does a full reload instead
                // of replaying, so a dropped signal can never desync it.
                'backlog' => 100,
            ],

            'null' => [
                'via' => NullReloadDriver::class,
            ],

        ],

        // Safety net. Every so often the server reloads everything from the
        // repository regardless of signals, so a missed change cannot go
        // unnoticed forever. Set to null to disable.
        'reconcile_every' => env('PATCHBAY_RECONCILE_INTERVAL', 300),

    ],

    /*
    |--------------------------------------------------------------------------
    | Terminate Connections On Revoke
    |--------------------------------------------------------------------------
    |
    | When an application is deactivated or deleted, disconnect the clients it
    | still has open. Without this, revoking an application only stops new
    | connections; existing ones keep working until they disconnect by
    | themselves, because they were authenticated when they connected.
    |
    */

    'terminate_on_revoke' => env('PATCHBAY_TERMINATE_ON_REVOKE', true),

    /*
    |--------------------------------------------------------------------------
    | Application Defaults
    |--------------------------------------------------------------------------
    |
    | Applied to every application that does not override them. These are the
    | settings Reverb defines on an application, read from the same environment
    | variables Reverb reads, so an existing .env keeps working unchanged.
    |
    */

    'defaults' => [

        'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),

        'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),

        'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),

        'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),

        'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),

        'allowed_origins' => ['*'],

        'rate_limiting' => [
            'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
            'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
            'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
            'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Samples what each application is doing, recorded from inside the running
    | server. Connection and channel counts are already in memory there, and
    | message throughput exists nowhere else — the HTTP API does not report it.
    |
    | Messages are counted in memory and written once per interval, so the
    | database never sits on the path of an individual frame.
    |
    */

    'metrics' => [

        'enabled' => env('PATCHBAY_METRICS_ENABLED', true),

        'model' => Metric::class,

        'table' => 'patchbay_metrics',

        // Which server a sample came from. Every Reverb server records on its
        // own timer, so a fleet reading is the latest sample from each of
        // them. Defaults to the machine's hostname, which is enough unless
        // you run more than one server per host.
        'server' => env('PATCHBAY_SERVER_NAME'),

        // How often a sample is written, in seconds. Shorter gives a finer
        // graph and more rows; an application recorded every minute produces
        // about 43,000 rows a month.
        'interval' => env('PATCHBAY_METRICS_INTERVAL', 60),

        // Samples older than this are removed by `patchbay:prune-metrics`.
        // Set to null to keep them forever.
        'retain_days' => env('PATCHBAY_METRICS_RETAIN_DAYS', 7),

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Server API
    |--------------------------------------------------------------------------
    |
    | How the control panel reaches the running server to ask what is actually
    | happening — connection counts, open channels, whether it is up. This is
    | an administrative address, not the one clients connect to: a panel
    | running alongside the server talks to it directly over localhost while
    | the outside world arrives over TLS through a proxy.
    |
    | Left null, the address is worked out from your reverb config: the bind
    | host and port it is actually listening on, with 0.0.0.0 treated as
    | 127.0.0.1 since a bind address is not dialable, and https when Reverb
    | will have configured TLS — either from explicit tls options or from a
    | Valet or Herd certificate matching REVERB_HOST. Set a url when the server
    | is somewhere the config cannot describe, such as another container.
    |
    */

    'server' => [

        'url' => env('PATCHBAY_SERVER_URL'),

        // Bounded so a server that has stopped responding cannot turn a page
        // load into a queue of hanging requests.
        'timeout' => env('PATCHBAY_SERVER_TIMEOUT', 2),

        // Locally issued Valet and Herd certificates are usually trusted by
        // the system already, so this rarely needs turning off.
        'verify' => env('PATCHBAY_SERVER_VERIFY', true),

        // A dashboard makes one request per application, so results are held
        // briefly. Set to 0 to always ask the server.
        'cache_for' => env('PATCHBAY_SERVER_CACHE_FOR', 5),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pusher API Address
    |--------------------------------------------------------------------------
    |
    | Where a consuming application sends broadcasts. This becomes the Reverb
    | application's "options", which is what Reverb itself uses to address an
    | app's HTTP API, and what a consumer puts in REVERB_HOST and friends.
    |
    | This is server-to-server traffic, so it is often a private address: a
    | container name on an internal network rather than a public domain.
    |
    */

    'options' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
    ],

];
