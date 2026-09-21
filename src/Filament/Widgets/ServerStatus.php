<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Server\ServerAddress;
use RobertBoes\Patchbay\Server\ServerApi;

class ServerStatus extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $running = app(ServerApi::class)->isRunning();
        $apps = config('patchbay.model', App::class);

        return [
            Stat::make(__('Server'), $running ? __('Running') : __('Unreachable'))
                ->description(app(ServerAddress::class)->url())
                ->color($running ? 'success' : 'danger')
                ->icon($running ? 'heroicon-m-signal' : 'heroicon-m-signal-slash'),

            Stat::make(__('Active applications'), $apps::query()->where('active', true)->count())
                ->description(__(':total in total', ['total' => $apps::query()->count()]))
                ->icon('heroicon-m-rectangle-stack'),

            $this->connectionsStat(),
        ];
    }

    protected function connectionsStat(): Stat
    {
        if (! config('patchbay.metrics.enabled', true)) {
            return $this->connections('—', __('Metrics are disabled'));
        }

        $samples = $this->latestSamplePerServer(config('patchbay.metrics.model', Metric::class));

        if ($samples->isEmpty()) {
            return $this->connections('—', __('Waiting for the first sample'));
        }

        return $this->connections(
            (string) $this->sumConnections($samples),
            $this->recordedDescription($samples),
        );
    }

    /**
     * @param  class-string  $model
     * @return Collection<int, Metric>
     */
    protected function latestSamplePerServer(string $model): Collection
    {
        return $model::query()
            ->select('server')
            ->selectRaw('MAX(recorded_at) as recorded_at')
            ->groupBy('server')
            ->get();
    }

    /**
     * @param  Collection<int, Metric>  $samples
     */
    protected function sumConnections(Collection $samples): int
    {
        $model = config('patchbay.metrics.model', Metric::class);

        return (int) $model::query()
            ->where(function ($query) use ($samples) {
                foreach ($samples as $sample) {
                    $query->orWhere(function ($sampleQuery) use ($sample) {
                        $sampleQuery->where('recorded_at', $sample->recorded_at);

                        // A null server groups as one, but `where` on null
                        // compares rather than matches and finds nothing.
                        $sample->server === null
                            ? $sampleQuery->whereNull('server')
                            : $sampleQuery->where('server', $sample->server);
                    });
                }
            })
            ->sum('connections');
    }

    /**
     * @param  Collection<int, Metric>  $samples
     */
    protected function recordedDescription(Collection $samples): string
    {
        $when = $samples->max('recorded_at')->diffForHumans();

        if ($samples->count() === 1) {
            return __('Recorded :when', ['when' => $when]);
        }

        return __('Recorded :when across :count servers', [
            'when' => $when,
            'count' => $samples->count(),
        ]);
    }

    protected function connections(string $value, string $description): Stat
    {
        return Stat::make(__('Connections'), $value)
            ->description($description)
            ->icon('heroicon-m-users');
    }
}
