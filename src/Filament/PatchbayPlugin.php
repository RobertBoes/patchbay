<?php

namespace RobertBoes\Patchbay\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

/**
 * Registers Patchbay's dashboard with a Filament panel:
 *
 *     $panel->plugin(PatchbayPlugin::make())
 */
class PatchbayPlugin implements Plugin
{
    protected bool $registersResource = true;

    /** @var array<int, class-string>|null */
    protected ?array $dashboardWidgets = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'patchbay';
    }

    /**
     * Leave the resource out, to register your own in its place.
     */
    public function withoutResource(bool $condition = true): static
    {
        $this->registersResource = ! $condition;

        return $this;
    }

    /**
     * Opt-in, because a dashboard is the application's own page and a package
     * should not help itself to it. Pass a list to choose which.
     *
     * @param  array<int, class-string>|null  $widgets
     */
    public function widgets(?array $widgets = null): static
    {
        $this->dashboardWidgets = $widgets ?? [
            Widgets\ServerStatus::class,
            Widgets\ConnectionsChart::class,
        ];

        return $this;
    }

    public function register(Panel $panel): void
    {
        if ($this->registersResource) {
            $panel->resources([AppResource::class]);
        }

        if ($this->dashboardWidgets !== null) {
            $panel->widgets($this->dashboardWidgets);
        }
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
