<?php

namespace RobertBoes\Patchbay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use RobertBoes\Patchbay\PatchbayServiceProvider;

abstract class TestCase extends Orchestra
{
    protected LoopInterface $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loop = new StreamSelectLoop();

        $this->app->instance(LoopInterface::class, $this->loop);
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Reverb\ReverbServiceProvider::class,
            \Laravel\Reverb\ApplicationManagerServiceProvider::class,
            PatchbayServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('reverb.apps.provider', 'patchbay');
        $app['config']->set('auth.providers.users.model', \RobertBoes\Patchbay\Tests\Fixtures\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
