<?php

namespace RobertBoes\Patchbay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RobertBoes\Patchbay\Models\Event;

class PruneEventsCommand extends Command
{
    protected $signature = 'patchbay:prune-events {--days= : Override the configured retention}';

    protected $description = 'Delete logged events older than the configured retention';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('patchbay.events.retain_days');

        if ($days === null) {
            $this->components->info('Retention is disabled, so nothing was pruned.');

            return self::SUCCESS;
        }

        $model = config('patchbay.events.model', Event::class);
        $before = Carbon::now()->subDays((int) $days);

        $deleted = $model::query()->where('recorded_at', '<', $before)->delete();

        $this->components->info("Pruned {$deleted} event(s) recorded before {$before->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
