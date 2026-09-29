<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Patchbay\Models\Event;

/**
 * The last events this application carried. Counts answer how much went
 * through; this answers what, which is the question you have when something
 * did not arrive.
 */
class EventLog extends TableWidget
{
    public ?Model $record = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) config('patchbay.events.enabled', true);
    }

    /**
     * A table widget polls on this rather than getPollingInterval, which it
     * never reads. It is the fallback: with the panel's own connection open,
     * the server says when to re-read and this rarely comes round first.
     */
    public function getTablePollingInterval(): ?string
    {
        return max(5, (int) config('patchbay.metrics.interval', 60)) . 's';
    }

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

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent events'))
            ->description(__('Written when the server flushes, so the newest may be a moment behind.'))
            ->query(fn(): Builder => $this->eventsQuery())
            ->defaultSort('recorded_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateHeading(__('Nothing carried yet'))
            ->emptyStateDescription(__('Events appear here once this application sends or receives one.'))
            ->columns([
                TextColumn::make('recorded_at')
                    ->label(__('When'))
                    ->dateTime('H:i:s')
                    ->description(fn(Event $record) => $record->recorded_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('direction')
                    ->label(__('Direction'))
                    ->badge()
                    ->formatStateUsing(fn(string $state) => $state === Event::SENT ? __('Sent') : __('Received'))
                    ->color(fn(string $state) => $state === Event::SENT ? 'success' : 'info'),

                TextColumn::make('event')
                    ->label(__('Event'))
                    ->searchable()
                    ->wrap(),

                TextColumn::make('channel')
                    ->label(__('Channel'))
                    ->placeholder(__('None'))
                    ->searchable(),

                TextColumn::make('payload')
                    ->label(__('Payload'))
                    ->placeholder(__('Not recorded'))
                    ->limit(60)
                    ->tooltip(fn(Event $record) => $record->payload)
                    ->wrap(),
            ]);
    }

    protected function eventsQuery(): Builder
    {
        $model = config('patchbay.events.model', Event::class);

        return $model::query()->where('app_id', $this->record?->getKey());
    }
}
