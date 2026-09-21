<?php

namespace RobertBoes\Patchbay\Tests\Fixtures;

use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RobertBoes\Patchbay\Filament\PatchbayPlugin;

/**
 * The panel the package's own tests run against.
 *
 * Exists so Patchbay's Filament layer is exercised by this package's CI rather
 * than only by an application that happens to install it — which is the only
 * way the supported Filament range can be tested at both ends.
 */
class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('testing')
            ->path('panel')
            ->plugin(PatchbayPlugin::make())
            ->middleware([
                EncryptCookies::class,
                ConvertEmptyStringsToNull::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authGuard('web');
    }
}
