<?php

namespace RobertBoes\Patchbay\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

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

    public function withoutResource(bool $condition = true): static
    {
        $this->registersResource = ! $condition;

        return $this;
    }

    /**
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
