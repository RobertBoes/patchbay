<?php

namespace RobertBoes\Patchbay\Console;

use Illuminate\Console\Command;
use RobertBoes\Patchbay\Snippets;
use RobertBoes\Patchbay\Models\App;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class CreateAppCommand extends Command
{
    protected $signature = 'patchbay:create-app
                            {name? : A name for the application}
                            {--origins=* : Origins allowed to connect, defaults to any}
                            {--inactive : Create the application without activating it}';

    protected $description = 'Create a Reverb application and print its credentials';

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: 'What should the application be called?',
                default: App::suggestName(),
                required: true,
                validate: fn(string $value) => strlen($value) > 255
                    ? 'The name may not be longer than 255 characters.'
                    : null,
            ));
        }

        if ($input->getOption('origins') === []) {
            $input->setOption('origins', $this->splitOrigins(text(
                label: 'Which origins may open a connection?',
                placeholder: 'https://example.com, https://app.example.com',
                hint: 'Separate several with a comma. Leave empty to allow any origin.',
            )));
        }

        if (! $input->getOption('inactive')) {
            $activate = confirm(
                label: 'Activate the application now?',
                default: true,
                hint: 'An inactive application refuses connections until you turn it on.',
            );

            $input->setOption('inactive', ! $activate);
        }
    }

    public function handle(): int
    {
        $model = config('patchbay.model', App::class);

        $app = $model::create([
            'name' => $this->argument('name') ?: App::suggestName(),
            'allowed_origins' => $this->option('origins') ?: ['*'],
            'active' => ! $this->option('inactive'),
        ]);

        $this->components->info("Created application [{$app->name}].");

        if (! $app->active) {
            $this->components->warn('It is inactive, so it refuses connections until you activate it.');
        }

        $this->newLine();
        $this->line(app(Snippets::class)->env($app));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function splitOrigins(string $answer): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $answer))));
    }
}
