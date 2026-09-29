<?php

namespace RobertBoes\Patchbay\Console;

use Illuminate\Console\Command;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Server\Health;
use RobertBoes\Patchbay\Server\HealthCheck;
use RobertBoes\Patchbay\Server\ServerApi;

class StatusCommand extends Command
{
    protected $signature = 'patchbay:status';

    protected $description = 'Show the Reverb server status and what each application is doing';

    public function handle(ServerApi $server, AppSource $source, HealthCheck $health): int
    {
        if (! $server->isRunning()) {
            $this->components->error('The Reverb server is not reachable.');

            $this->components->bulletList([
                'Is it running? `php artisan reverb:start`',
                'Patchbay is looking at ' . config('patchbay.server.url', 'the host and port from your reverb config') . '.',
                'Set PATCHBAY_SERVER_URL if the server listens somewhere else.',
                'Set PATCHBAY_SERVER_VERIFY=false if it serves a certificate this machine does not trust.',
            ]);

            return self::FAILURE;
        }

        if ($health->status() === Health::Degraded) {
            $this->components->warn(
                'The Reverb server answers, but is not reporting in or serves none of the active applications. '
                . 'Check its output, then restart it.',
            );
        } else {
            $this->components->info('The Reverb server is running.');
        }

        $applications = $source->load();

        if ($applications->isEmpty()) {
            $this->components->warn('No active applications. Create one with `patchbay:create-app`.');

            return self::SUCCESS;
        }

        $this->table(
            ['App ID', 'Key', 'Connections', 'Channels'],
            $applications->map(function ($application) use ($server) {
                $metrics = $server->metrics($application);

                return [
                    $application->id(),
                    $application->key(),
                    $metrics->available ? $metrics->connections : '?',
                    $metrics->available ? (implode(', ', $metrics->channelNames()) ?: '—') : '?',
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
