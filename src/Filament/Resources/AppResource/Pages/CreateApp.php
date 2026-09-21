<?php

namespace RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

class CreateApp extends CreateRecord
{
    protected static string $resource = AppResource::class;

    protected function getRedirectUrl(): string
    {
        session()->put('patchbay.reveal', $this->record->getKey());

        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
