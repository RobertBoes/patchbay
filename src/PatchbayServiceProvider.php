<?php

namespace RobertBoes\Patchbay;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Reverb\ApplicationManager;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Contracts\ReloadDriver;
use RobertBoes\Patchbay\Exceptions\InvalidReloadDriver;
use RobertBoes\Patchbay\Metrics\MetricsRecorder;
use RobertBoes\Patchbay\Server\Heartbeat;
use RobertBoes\Patchbay\Server\ServerAddress;
use RobertBoes\Patchbay\Server\ServerApi;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PatchbayServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('patchbay')
            ->hasConfigFile('patchbay')
            ->hasMigrations([
                'create_patchbay_apps_table',
                'create_patchbay_metrics_table',
            ])
            ->hasCommands([
                Console\InstallCommand::class,
                Console\CreateAppCommand::class,
                Console\StatusCommand::class,
                Console\PruneMetricsCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        // The loop Reverb runs. Bound so nothing else has to reach for the global.
        $this->app->bindIf(LoopInterface::class, fn() => Loop::get());

        $this->app->singleton(Registry::class);

        $this->app->singleton(ApplicationFactory::class);

        $this->app->singleton(MetricsRecorder::class, fn($app) => new MetricsRecorder(
            container: $app,
            registry: $app->make(Registry::class),
            model: $app['config']->get('patchbay.metrics.model', Models\Metric::class),
            server: $this->serverName(),
        ));

        $this->app->singleton(Heartbeat::class, fn($app) => new Heartbeat(
            // The store the reload driver already requires to be shared
            // between the panel and the server.
            cache: $app->make(CacheFactory::class)->store(
                $app['config']->get('patchbay.reload.drivers.cache.store'),
            ),
            server: $this->serverName() ?? 'default',
        ));

        $this->app->singleton(ServerApi::class, fn($app) => new ServerApi(
            http: $app->make(Http::class),
            cache: $app->make(CacheFactory::class)->store(
                $app['config']->get('patchbay.server.cache_store'),
            ),
            address: $app->make(ServerAddress::class),
            config: (array) $app['config']->get('patchbay.server', []),
        ));

        $this->app->singleton(AppSource::class, function ($app) {
            $source = $app['config']->get('patchbay.source', Sources\EloquentAppSource::class);

            return $app->make($source, [
                'model' => $app['config']->get('patchbay.model', Models\App::class),
            ]);
        });

        $this->app->singleton(ReloadDriver::class, fn($app) => $this->resolveReloadDriver($app));

        $this->app->singleton(RegistryApplicationProvider::class);

        $this->app->singleton(Reloader::class, fn($app) => new Reloader(
            registry: $app->make(Registry::class),
            source: $app->make(AppSource::class),
            driver: $app->make(ReloadDriver::class),
            terminator: $app->make(ConnectionTerminator::class),
            container: $app,
            loop: $app->make(LoopInterface::class),
            config: $app['config'],
            heartbeat: $app->make(Heartbeat::class),
            terminateOnRevoke: (bool) $app['config']->get('patchbay.terminate_on_revoke', true),
        ));
    }

    public function packageBooted(): void
    {
        $this->registerReverbDriver();
        $this->listenForServerStart();
        $this->clearRegistryBetweenOctaneRequests();
    }

    protected function registerReverbDriver(): void
    {
        // Captured, because Manager::extend() rebinds the callback's $this to
        // the manager, where $this->app would resolve against the wrong object.
        $container = $this->app;

        $this->callAfterResolving(
            ApplicationManager::class,
            fn(ApplicationManager $manager) => $manager->extend(
                'patchbay',
                fn() => $container->make(RegistryApplicationProvider::class),
            ),
        );
    }

    protected function listenForServerStart(): void
    {
        $this->app->make(Dispatcher::class)->listen(
            CommandStarting::class,
            function (CommandStarting $event) {
                if ($event->command !== 'reverb:start') {
                    return;
                }

                // Deferred to the loop's first tick: Reverb's Log memoises the first
                // logger it resolves, and logging before the command installs its own
                // would pin the null logger and silence the server's --debug.
                $this->app->make(LoopInterface::class)->futureTick(
                    fn() => $this->app->make(Reloader::class)->start(),
                );
            },
        );
    }

    protected function clearRegistryBetweenOctaneRequests(): void
    {
        if (! class_exists(RequestReceived::class)) {
            return;
        }

        $this->app->make(Dispatcher::class)->listen(
            RequestReceived::class,
            fn() => $this->app->make(Registry::class)->flush(),
        );
    }

    /** Which server this process is, as recorded in metrics and heartbeats. */
    protected function serverName(): ?string
    {
        return $this->app['config']->get('patchbay.metrics.server') ?: (gethostname() ?: null);
    }

    protected function resolveReloadDriver(Container $container): ReloadDriver
    {
        $config = $container->make('config');
        $name = $config->get('patchbay.reload.driver', 'cache');
        $drivers = (array) $config->get('patchbay.reload.drivers', []);

        if (! isset($drivers[$name])) {
            throw InvalidReloadDriver::notConfigured($name, array_keys($drivers));
        }

        $settings = (array) $drivers[$name];

        if (! $via = $settings['via'] ?? null) {
            throw InvalidReloadDriver::missingClass($name);
        }

        // Applies to whichever driver is in use, so it is not repeated per
        // driver in config.
        $settings['reconcile_every'] = $config->get('patchbay.reload.reconcile_every', 300);

        return $container->make($via, ['config' => $settings]);
    }
}
