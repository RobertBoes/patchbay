<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use RobertBoes\Patchbay\Models\Metric;

/**
 * Connections and message throughput over the last day, read from recorded
 * samples rather than the server, so it still draws when the server is down.
 */
class ConnectionsChart extends ChartWidget
{
    protected ?string $heading = 'Connections';

    protected int|string|array $columnSpan = 'full';

    // Uncapped, a line chart fills most of a narrow screen.
    protected ?string $maxHeight = '260px';

    /**
     * Polling faster than the recording interval redraws the same picture.
     */
    public function getPollingInterval(): ?string
    {
        return max(30, (int) config('patchbay.metrics.interval', 60)) . 's';
    }

    protected function getData(): array
    {
        $model = config('patchbay.metrics.model', Metric::class);

        $samples = $model::query()
            ->where('recorded_at', '>=', Carbon::now()->subDay())
            ->orderBy('recorded_at')
            ->get()
            ->groupBy(fn(Metric $metric) => $metric->recorded_at->format('Y-m-d H:i'));

        return [
            'datasets' => [
                [
                    'label' => __('Connections'),
                    'data' => $samples->map(fn($group) => $group->sum('connections'))->values()->all(),
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => __('Messages'),
                    'data' => $samples->map(
                        fn($group) => $group->sum('messages_sent') + $group->sum('messages_received'),
                    )->values()->all(),
                    'borderColor' => 'rgb(99, 102, 241)',
                    'fill' => false,
                ],
            ],
            'labels' => $samples->keys()->map(fn(string $at) => substr($at, 11))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
