<?php

namespace RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;

use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

class CreateApp extends CreateRecord
{
    protected static string $resource = AppResource::class;

    /**
     * Opening the page past the limit is a 403, which is right. A limit
     * reached while the page was open, say from another tab, would get the
     * same bare 403 on submit; this explains it instead.
     */
    /**
     * Filament re-checks access on every request, answering a limit reached
     * mid-page with a bare 403 before create() can say why. The page is still
     * checked as it opens, and create() checks before anything is written.
     */
    public function hydrate(): void
    {
        //
    }

    public function create(bool $another = false): void
    {
        $response = static::getResource()::getCreateAuthorizationResponse();

        if ($response->denied()) {
            // Straight to this page rather than through the session. The limit
            // was usually reached from another open tab, and a session
            // notification goes to whichever tab asks for it first.
            $this->dispatch('notificationSent', notification: Notification::make()
                ->title($response->message() ?? __('You cannot create another application.'))
                ->danger()
                ->toArray());

            return;
        }

        parent::create($another);
    }

    protected function getRedirectUrl(): string
    {
        session()->put('patchbay.reveal', $this->record->getKey());

        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
