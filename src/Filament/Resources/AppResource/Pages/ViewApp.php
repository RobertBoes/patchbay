<?php

namespace RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RobertBoes\Patchbay\Filament\Resources\AppResource;
use RobertBoes\Patchbay\Filament\Widgets;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Server\ServerApi;

class ViewApp extends ViewRecord
{
    protected static string $resource = AppResource::class;

    public bool $secretRevealed = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // pull() consumes the handover, so a refresh does not repeat it.
        $this->secretRevealed = session()->pull('patchbay.reveal') === $this->record->getKey();
    }

    protected function getFooterWidgets(): array
    {
        return [
            Widgets\LiveUpdates::class,
            Widgets\AppStats::class,
            Widgets\DebugConsole::class,
            Widgets\AppConnectionsChart::class,
            Widgets\EventLog::class,
        ];
    }

    /**
     * The Live section is rendered here rather than in a widget, so the page
     * itself re-reads when the server reports a change.
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return ['patchbay-stats-changed' => '$refresh'];
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->revealAction(),
            $this->rotateSecretAction(),
            $this->disconnectUserAction(),
            $this->activationAction(),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * Ending a session, not barring anyone: the connections close and the
     * client is free to come straight back. Deactivating the application is
     * what keeps someone out.
     */
    protected function disconnectUserAction(): Action
    {
        return Action::make('disconnectUser')
            ->label(__('Disconnect a user'))
            ->icon('heroicon-m-signal-slash')
            ->color('gray')
            ->visible(fn() => $this->record->active)
            ->schema([
                TextInput::make('user')
                    ->label(__('User ID'))
                    ->helperText(__('As the connection authenticated, shown against presence channels.'))
                    ->required(),
            ])
            ->requiresConfirmation()
            ->modalDescription(__(
                'Every connection this user holds on this application closes. They '
                . 'can reconnect straight away; deactivate the application to keep '
                . 'them out.',
            ))
            ->action(function (array $data) {
                $application = app(AppSource::class)->loadById($this->record->getKey());

                $terminated = $application
                    && app(ServerApi::class)->terminateUser($application, (string) $data['user']);

                if (! $terminated) {
                    Notification::make()
                        ->title(__('Could not disconnect that user'))
                        ->body(__('The server did not accept the request. It may be unreachable.'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Disconnected'))
                    ->body(__('Any connections :user held are closed.', ['user' => $data['user']]))
                    ->success()
                    ->send();
            });
    }

    protected function revealAction(): Action
    {
        return Action::make('reveal')
            ->label(fn() => $this->secretRevealed ? __('Hide secret') : __('Reveal secret'))
            ->icon(fn() => $this->secretRevealed ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')
            ->color('gray')
            ->action(fn() => $this->secretRevealed = ! $this->secretRevealed);
    }

    protected function rotateSecretAction(): Action
    {
        return Action::make('rotateSecret')
            ->label(__('Rotate secret'))
            ->icon('heroicon-m-arrow-path')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__(
                'The current secret stops working as soon as the server picks up the '
                . 'change. Anything broadcasting with it will fail until it is updated.',
            ))
            ->action(function () {
                // The secret is not mass assignable, so update() would drop it.
                $this->record->secret = App::generateSecret();
                $this->record->save();

                $this->secretRevealed = true;

                Notification::make()
                    ->title(__('Secret rotated'))
                    ->body(__('Update the consuming application with the new secret.'))
                    ->warning()
                    ->send();
            });
    }

    protected function activationAction(): Action
    {
        return Action::make('activation')
            ->label(fn() => $this->isActive() ? __('Deactivate') : __('Activate'))
            ->icon(fn() => $this->isActive() ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle')
            ->color(fn() => $this->isActive() ? 'warning' : 'success')
            ->requiresConfirmation(fn() => $this->isActive())
            ->modalDescription(fn() => $this->isActive() ? AppResource::revokeWarning() : null)
            ->action(function () {
                $this->record->update(['active' => ! $this->isActive()]);

                Notification::make()
                    ->title($this->isActive() ? __('Application activated') : __('Application deactivated'))
                    ->success()
                    ->send();
            });
    }

    protected function isActive(): bool
    {
        return (bool) $this->record->active;
    }
}
