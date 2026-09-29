<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Reverb\Application;

class ApplicationFactory
{
    public function __construct(protected Config $config)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function make(array $attributes): Application
    {
        return new Application(
            id: (string) $attributes['id'],
            key: (string) $attributes['key'],
            secret: (string) $attributes['secret'],
            pingInterval: (int) $this->setting($attributes, 'ping_interval'),
            activityTimeout: (int) $this->setting($attributes, 'activity_timeout'),
            allowedOrigins: $this->allowedOrigins($attributes),
            maxMessageSize: (int) $this->setting($attributes, 'max_message_size'),
            maxConnections: $this->nullableInt($this->setting($attributes, 'max_connections')),
            acceptClientEventsFrom: (string) $this->setting($attributes, 'accept_client_events_from'),
            rateLimiting: $this->rateLimiting($attributes),
            options: $this->options($attributes),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function setting(array $attributes, string $key): mixed
    {
        // Empty falls back as well as null: a form field left blank saves [],
        // which as allowed_origins would reach Reverb as an allowlist that
        // admits no one.
        return filled($attributes[$key] ?? null)
            ? $attributes[$key]
            : $this->config->get("patchbay.defaults.{$key}");
    }

    /**
     * Reverb matches each entry against the host of the connection's Origin
     * header alone. "https://example.com" would never equal "example.com", so
     * an origin written as a URL is reduced to its host rather than silently
     * refusing every client.
     *
     * An application that restricts its origins also admits the panel's own,
     * or the debug console could not connect to it; nobody should have to
     * list the dashboard they are already using.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, string>
     */
    protected function allowedOrigins(array $attributes): array
    {
        $origins = array_values(array_map(
            fn(string $origin) => $this->host($origin),
            (array) $this->setting($attributes, 'allowed_origins'),
        ));

        $panel = $this->panelOrigin();

        if (in_array('*', $origins, strict: true) || $panel === null || in_array($panel, $origins, strict: true)) {
            return $origins;
        }

        return [...$origins, $panel];
    }

    protected function panelOrigin(): ?string
    {
        $origin = $this->config->get('patchbay.panel_origin') ?: $this->config->get('app.url');

        return filled($origin) ? $this->host((string) $origin) : null;
    }

    protected function host(string $origin): string
    {
        return str_contains($origin, '://')
            ? (string) parse_url($origin, PHP_URL_HOST)
            : $origin;
    }

    protected function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * Cast rather than passed through: env() and the form both hand over
     * strings, and Reverb gives decay_seconds to Carbon as it is, which throws
     * on "60" and fails every message the connection sends.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{enabled: bool, max_attempts: int, decay_seconds: int, terminate_on_limit: bool}|null
     */
    protected function rateLimiting(array $attributes): ?array
    {
        $limits = $this->setting($attributes, 'rate_limiting');

        if (! is_array($limits) || ! filter_var($limits['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return [
            'enabled' => true,
            'max_attempts' => (int) ($limits['max_attempts'] ?? 60),
            'decay_seconds' => (int) ($limits['decay_seconds'] ?? 60),
            'terminate_on_limit' => filter_var($limits['terminate_on_limit'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function options(array $attributes): array
    {
        return array_merge(
            (array) $this->config->get('patchbay.options', []),
            (array) ($attributes['options'] ?? []),
        );
    }
}
