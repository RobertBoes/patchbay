<?php

namespace RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RobertBoes\Patchbay\Filament\Resources\AppResource;
use RobertBoes\Patchbay\Models\App;

class ViewApp extends ViewRecord
{
    protected static string $resource = AppResource::class;

    /**
     * Component state rather than the session, so the secret stays visible for
     * exactly as long as the page is open.
     */
    public bool $secretRevealed = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // pull() consumes the handover, so a refresh does not repeat it.
        $this->secretRevealed = session()->pull('patchbay.reveal') === $this->record->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->revealAction(),
            $this->rotateSecretAction(),
            $this->activationAction(),
            EditAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * The secret is encrypted rather than hashed, so it can be shown again.
     * Showing it only on request keeps it off an unattended screen.
     */
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

    /**
     * Deactivating is the revoke button: it stops new connections and drops the
     * ones the application already has.
     *
     * Every label is a closure because Filament caches the action object for the
     * lifetime of the component, so a computed value would not survive a click.
     */
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
