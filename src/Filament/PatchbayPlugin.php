<?php

namespace RobertBoes\Patchbay\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Patchbay\Filament\Resources\AppResource;

class PatchbayPlugin implements Plugin
{
    protected bool $registersResource = true;

    /** @var array<int, class-string>|null */
    protected ?array $dashboardWidgets = null;

    /** @var (Closure(?Model): ?int)|null */
    protected ?Closure $connectionLimit = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'patchbay';
    }

    /** This plugin as registered on the current panel, if it is. */
    public static function current(): ?static
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        return $panel?->hasPlugin('patchbay') ? $panel->getPlugin('patchbay') : null;
    }

    /**
     * The most connections an application may be given, shown on its form and
     * enforced as the field's maximum. The callback receives the application
     * being edited, or null when creating one; returning null is unlimited.
     *
     * @param  (Closure(?Model): ?int)|null  $limit
     */
    public function connectionLimit(?Closure $limit): static
    {
        $this->connectionLimit = $limit;

        return $this;
    }

    public function getConnectionLimit(?Model $application = null): ?int
    {
        return $this->connectionLimit ? ($this->connectionLimit)($application) : null;
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
