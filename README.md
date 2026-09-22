# Patchbay

Dynamic [Laravel Reverb](https://reverb.laravel.com) applications. Keep your WebSocket
apps in the database, manage them at runtime, and let a running server pick up changes
without dropping a single connection.

Reverb reads its applications from a static config array. That is fine for one app and
awkward for anything else: adding a tenant means a deploy, and changing one means
restarting the server, which disconnects every client of every other app. Reverb's
`ApplicationManager` is an Illuminate `Manager`, so the set of applications is a driver
like any other. Patchbay is that driver.

```
composer require robertboes/patchbay
php artisan patchbay:install
php artisan migrate
```

Then point Reverb at it:

```env
REVERB_PROVIDER=patchbay
```

And create an application:

```
php artisan patchbay:create-app
```

It asks for a name, the origins allowed to connect, and whether to activate it, then
prints the `.env` block for the consuming application. Pass any of them to skip the
question, which is also how you script it:

```
php artisan patchbay:create-app checkout --origins=https://example.com --no-interaction
```

## How it works

Three pieces, each doing one thing:

**A source** owns the data. `AppSource` is the slow, authoritative side — it is read when
the server boots and when an application actually changes, and never on the connection
path. The default reads from the database with Eloquent. Point `patchbay.source` at your
own implementation to load applications from somewhere else.

**A registry** answers lookups. Reverb's `ApplicationProvider::findByKey()` returns an
`Application`, not a promise, so a lookup cannot await I/O — and anything it does
synchronously blocks the event loop and therefore every connection on the server. The
registry keeps applications in memory so that lookup is an array read. At roughly 600
bytes per application that is about 6MB for 10,000 apps, an order of magnitude less than
the connections serving them.

**A reload driver** keeps the two in step. When an application changes, the driver
carries *which* application changed to the running server, which reloads that one. The
difference matters: a full reload of 100,000 applications blocks the event loop for about
300ms, where reloading a single application is an indexed query at around 60 microseconds.

The default `cache` driver needs no extra infrastructure — it is the same shape as
Reverb's own `reverb:restart`, which polls a cache key on a five second timer. It keeps a
bounded log of recent changes, and if the server ever falls further behind than that log
it reloads everything rather than replaying a partial one, so a dropped signal is a slow
refresh instead of a server quietly serving stale credentials.

### Where the registry lives

Patchbay runs in two very different processes and tunes itself to both:

- **Inside the Reverb server** the registry is filled at boot and kept current by the
  reload driver. Every lookup is memory; nothing touches the database per connection.
- **Inside a web request** the registry starts empty, so a lookup falls through to a
  single indexed query and memoises it for the rest of the request. Under Octane it is
  dropped between requests, because a long-lived container would otherwise keep serving
  applications that have since changed.

## Dashboard

Patchbay ships a Filament panel. Register the plugin on a panel and applications become
manageable there:

```php
use RobertBoes\Patchbay\Filament\PatchbayPlugin;

$panel->plugin(PatchbayPlugin::make());
```

Filament is not a dependency of this package — nothing in `src/Filament` is reachable
until an application registers the plugin, at which point Filament is necessarily
installed. Filament 4 and 5 are both supported; their resource APIs are identical.

The application page shows live connection counts read from the running server, reveals
the secret on request, and gives you the `.env` block to paste into the consuming
application. Deactivating an application from here disconnects its clients.

Widgets are not added to your dashboard unless you ask, since a dashboard is your page
and not a package's:

```php
$panel->plugin(PatchbayPlugin::make()->widgets());
```

Pass a list to choose which. They also appear above the application list either way.
`->withoutResource()` leaves the built-in resource out, to register your own.

> Filament grants panel access automatically only in the `local` environment. In any
> other environment your `User` model needs to implement `Filament\Models\Contracts\FilamentUser`,
> or the panel returns 403 — this is Filament's behaviour, not Patchbay's.

## Metrics

Patchbay records what each application is doing from inside the running server, on a
timer. Connection and channel counts are already in memory there, so a sample costs
nothing and does not depend on the server being reachable from wherever the dashboard
runs. Message throughput is recorded the same way and is not available any other way —
Reverb's HTTP API does not report it.

Messages are counted in memory and written once per interval, so the database never sits
on the path of an individual frame.

```
php artisan patchbay:prune-metrics
```

Schedule that alongside your other pruning. Samples older than
`patchbay.metrics.retain_days` are removed; one application recorded every minute
produces about 43,000 rows a month. Set `PATCHBAY_METRICS_ENABLED=false` to record
nothing.

## Seeing what the server is doing

```
php artisan patchbay:status
```

Connection counts and open channels live only in the running server's memory, not in
the database, so Patchbay asks it over Reverb's HTTP API. The address is worked out
from your reverb config and normally needs no configuration at all.

Two things about that are worth knowing, because Reverb's settings do not mean what
their names suggest:

`REVERB_SERVER_HOST` is the interface the server **listens on**, while `REVERB_HOST` is
the name it is **reached by**. Setting `REVERB_HOST` does not move the server, but
Reverb looks for a Valet or Herd certificate matching that name and serves TLS if it
finds one. Patchbay asks the same question, so it knows to use `https` and to address
the server by the name on the certificate rather than by its bind address.

Set `PATCHBAY_SERVER_URL` when the server is somewhere the config cannot describe, such
as another container.

Every call is bounded by a timeout and cached for a few seconds, so a server that has
stopped responding cannot turn a dashboard render into a queue of hanging requests.
Results distinguish "no connections" from "could not reach the server" rather than
reporting zero for both.

## Configuration

Everything has a working default, so the package functions before you publish anything.
`config/patchbay.php` covers the source, the reload driver and its interval, per-app
defaults, and the connection options handed to clients.

**Patchbay does not replace your Reverb config.** You run `reverb:start` exactly as
before; Patchbay only supplies the applications. Anything Reverb already decides —
where it binds, which port, whether it serves TLS — is read from `config/reverb.php`
rather than restated here.

Per-application defaults are the settings Reverb defines on an application —
`ping_interval`, `activity_timeout`, `max_message_size`, `max_connections`,
`allowed_origins`, `accept_client_events_from` and `rate_limiting` — and they read the
same `REVERB_APP_*` environment variables Reverb reads, so an existing `.env` keeps
working. Switching provider empties Reverb's `apps.apps` array; it does not change
what an application means.

> **The cache store must be shared between processes.** The control panel and the Reverb
> server are different processes, so the `array` store cannot carry changes between them.
> `file` and `database` both work on a single host; use `redis` or `memcached` when the
> panel and the server run on different machines. Set `PATCHBAY_CACHE_STORE` to pick a
> store other than the application default.

## Revoking an application

Deactivating or deleting an application disconnects the clients it still has open, not
just the ones that try to connect afterwards. An open connection was authenticated when
it was established, so without this it would keep working indefinitely.

Reverb tracks connections through channels and keeps no separate list of them, so this
reaches every connection subscribed to at least one channel — the same set Reverb's own
shutdown disconnects. A client that has connected but subscribed to nothing is not
enumerable and survives until it subscribes or its activity timeout expires.

Set `patchbay.terminate_on_revoke` to `false` to leave open connections alone.

## Secrets

An application's key is public and travels to the browser. Its secret signs Pusher
requests and never leaves the server, so it is stored encrypted rather than hashed —
HMAC signing needs the original value back. The model hides it by default; the
`patchbay:create-app` command prints it once.

Rotating `APP_KEY` re-encrypts nothing on its own. If you rotate it, re-encrypt the
`secret` column in the same deploy or every application will stop authenticating.

## Requirements

- PHP 8.2+
- Laravel 12 or 13
- Reverb 1.9+

Reverb's `Application` constructor gained parameters in 1.6, 1.8 and 1.9, each inserted
before `$options`. Patchbay passes every argument by name and requires 1.9 so that the
full set of per-application settings is available on a single code path. If you need
support for an earlier Reverb, open an issue.

## Testing

```
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
