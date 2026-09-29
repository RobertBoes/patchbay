<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Server\ServerAddress;
use RobertBoes\Patchbay\Server\ServerApi;
use RobertBoes\Patchbay\Server\Health;
use RobertBoes\Patchbay\Server\HealthCheck;

class ServerStatus extends StatsOverviewWidget
{
    /**
     * A reading differing from the last one reaches the panel over the
     * server's own WebSocket, and this re-reads rather than waiting for the
     * next poll.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return ['patchbay-stats-changed' => '$refresh'];
    }

    protected function getStats(): array
    {
        $health = app(HealthCheck::class)->status();

        // Only worth asking once the server itself is fine.
        $unanswered = $health === Health::Operational && ! app(ServerApi::class)->isPubliclyReachable();
        $apps = config('patchbay.model', App::class);

        return [
            Stat::make(__('Server'), $health->label())
                ->description($this->serverDescription($health, $unanswered))
                ->descriptionColor($unanswered ? 'warning' : null)
                ->descriptionIcon($unanswered ? 'heroicon-m-exclamation-triangle' : null)
                ->color($health->color())
                ->icon($health === Health::Down ? 'heroicon-m-signal-slash' : 'heroicon-m-signal'),

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

    /**
     * The address clients use, not the one the panel dials: behind a proxy
     * that is an internal name nobody outside could reach.
     */
    protected function serverDescription(Health $health, bool $unanswered): string
    {
        if ($health === Health::Degraded) {
            return __('Answering, but not serving applications');
        }

        $address = Str::replaceFirst('http', 'ws', app(ServerAddress::class)->publicUrl());

        return $unanswered
            ? __(':address does not answer from here', ['address' => $address])
            : $address;
    }

    protected function connections(string $value, string $description): Stat
    {
        return Stat::make(__('Connections'), $value)
            ->description($description)
            ->icon('heroicon-m-users');
    }
}
