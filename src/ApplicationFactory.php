<?php

namespace RobertBoes\Patchbay;

use Illuminate\Contracts\Config\Repository as Config;
use Laravel\Reverb\Application;

/**
 * Every argument is passed by name because Reverb's Application constructor has
 * three times inserted a new parameter before $options. Positional arguments
 * silently shift on upgrade.
 */
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
            allowedOrigins: (array) $this->setting($attributes, 'allowed_origins'),
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
        return $attributes[$key] ?? $this->config->get("patchbay.defaults.{$key}");
    }

    protected function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    protected function rateLimiting(array $attributes): ?array
    {
        $limits = $this->setting($attributes, 'rate_limiting');

        if (! is_array($limits) || ! ($limits['enabled'] ?? false)) {
            return null;
        }

        return $limits;
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
