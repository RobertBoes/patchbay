<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobertBoes\Patchbay\Models\Metric;

/**
 * Recorded totals for one application, alongside the live readings the
 * infolist takes straight from the server.
 */
class AppStats extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) config('patchbay.metrics.enabled', true);
    }

    public function getPollingInterval(): ?string
    {
        return max(30, (int) config('patchbay.metrics.interval', 60)) . 's';
    }

    protected function getStats(): array
    {
        $messages = $this->messageTotals();

        if ($messages === null) {
            return [
                $this->stat(__('Messages sent'), '—', 'heroicon-m-arrow-up-tray'),
                $this->stat(__('Messages received'), '—', 'heroicon-m-arrow-down-tray'),
                $this->stat(__('Peak connections'), '—', 'heroicon-m-users'),
            ];
        }

        return [
            $this->stat(
                __('Messages sent'),
                number_format((int) $messages->sent),
                'heroicon-m-arrow-up-tray',
            ),
            $this->stat(
                __('Messages received'),
                number_format((int) $messages->received),
                'heroicon-m-arrow-down-tray',
            ),
            $this->stat(
                __('Peak connections'),
                number_format($this->peakConnections()),
                'heroicon-m-users',
            ),
        ];
    }

    /**
     * Messages are counters reset at every flush, so a window's traffic is
     * the sum of its samples. Null when the application has none yet.
     */
    protected function messageTotals(): ?object
    {
        $totals = $this->recordedMetrics()
            ->selectRaw('COUNT(*) as samples, SUM(messages_sent) as sent, SUM(messages_received) as received')
            ->first();

        return (int) $totals->samples > 0 ? $totals : null;
    }

    /**
     * Connections are a gauge, so the peak is the highest the servers held
     * between them at any one moment, not the highest any one of them saw.
     */
    protected function peakConnections(): int
    {
        return (int) $this->recordedMetrics()
            ->selectRaw('SUM(connections) as total')
            ->groupBy('recorded_at')
            ->orderByDesc('total')
            ->value('total');
    }

    protected function recordedMetrics(): Builder
    {
        $model = config('patchbay.metrics.model', Metric::class);

        return $model::query()
            ->where('app_id', $this->record?->getKey())
            ->where('recorded_at', '>=', Carbon::now()->subDay());
    }

    protected function stat(string $label, string $value, string $icon): Stat
    {
        return Stat::make($label, $value)
            ->description(__('Last 24 hours'))
            ->icon($icon);
    }
}
