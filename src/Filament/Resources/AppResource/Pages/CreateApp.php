<?php

namespace RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

class CreateApp extends CreateRecord
{
    protected static string $resource = AppResource::class;

    /**
     * Hand the secret over once, on the one screen where it is certain to be
     * wanted. The view page consumes the flag on mount, so a refresh hides it.
     */
    protected function getRedirectUrl(): string
    {
        session()->put('patchbay.reveal', $this->record->getKey());

        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
