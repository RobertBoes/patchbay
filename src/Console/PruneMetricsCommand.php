<?php

namespace RobertBoes\Patchbay\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneMetricsCommand extends Command
{
    protected $signature = 'patchbay:prune-metrics {--days= : Override the configured retention}';

    protected $description = 'Delete metric samples older than the configured retention';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('patchbay.metrics.retain_days');

        if ($days === null) {
            $this->components->info('Retention is disabled, so nothing was pruned.');

            return self::SUCCESS;
        }

        $model = config('patchbay.metrics.model', \RobertBoes\Patchbay\Models\Metric::class);
        $before = Carbon::now()->subDays((int) $days);

        $deleted = $model::query()->where('recorded_at', '<', $before)->delete();

        $this->components->info("Pruned {$deleted} sample(s) recorded before {$before->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
