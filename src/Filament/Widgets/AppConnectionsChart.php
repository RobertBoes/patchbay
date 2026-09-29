<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The dashboard chart, narrowed to one application.
 */
class AppConnectionsChart extends ConnectionsChart
{
    public ?Model $record = null;

    protected ?string $heading = 'Activity';

    public static function canView(): bool
    {
        return (bool) config('patchbay.metrics.enabled', true);
    }

    protected function metricsQuery(): Builder
    {
        return parent::metricsQuery()->where('app_id', $this->record?->getKey());
    }
}
