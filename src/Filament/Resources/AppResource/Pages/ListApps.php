<?php

namespace RobertBoes\Patchbay\Filament\Resources\AppResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

class ListApps extends ListRecords
{
    protected static string $resource = AppResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function getHeaderWidgets(): array
    {
        return AppResource::getWidgets();
    }
}
