<?php

namespace RobertBoes\Patchbay\Console;

use Illuminate\Console\Command;
use RobertBoes\Patchbay\EnvSnippet;
use RobertBoes\Patchbay\Models\App;

class AppCommand extends Command
{
    protected $signature = 'patchbay:app
                            {name? : A name for the application}
                            {--origins=* : Origins allowed to connect, defaults to any}
                            {--inactive : Create the application without activating it}';

    protected $description = 'Create a Reverb application and print its credentials';

    public function handle(): int
    {
        $model = config('patchbay.model', App::class);

        $app = $model::create([
            'name' => $this->argument('name') ?: 'app-' . str()->random(6),
            'allowed_origins' => $this->option('origins') ?: ['*'],
            'active' => ! $this->option('inactive'),
        ]);

        $this->components->info("Created application [{$app->name}].");

        $this->newLine();
        $this->line(app(EnvSnippet::class)->for($app));
        $this->newLine();

        return self::SUCCESS;
    }
}
