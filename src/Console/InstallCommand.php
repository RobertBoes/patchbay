<?php

namespace RobertBoes\Patchbay\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'patchbay:install {--force : Overwrite an existing config file}';

    protected $description = 'Publish Patchbay\'s config and migration';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', [
            '--tag' => 'patchbay-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->callSilently('vendor:publish', ['--tag' => 'patchbay-migrations']);

        $this->components->info('Patchbay config and migration published.');

        $this->components->bulletList([
            'Run `php artisan migrate` to create the applications table.',
            'Set REVERB_PROVIDER=patchbay so Reverb reads applications from Patchbay.',
            'Create your first application with `php artisan patchbay:app <name>`.',
        ]);

        $store = config('patchbay.reload.drivers.cache.store') ?? config('cache.default');

        if ($store === 'array') {
            $this->components->warn(
                'Your cache store is "array", which does not outlive the process that wrote to it. '
                . 'The Reverb server will never see application changes. '
                . 'Use a persistent store (file, database, redis, memcached) or set PATCHBAY_CACHE_STORE.',
            );
        }

        return self::SUCCESS;
    }
}
