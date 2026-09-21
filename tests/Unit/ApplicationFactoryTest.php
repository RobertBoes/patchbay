<?php

namespace RobertBoes\Patchbay\Tests\Unit;

use Laravel\Reverb\Application;
use ReflectionMethod;
use RobertBoes\Patchbay\ApplicationFactory;
use RobertBoes\Patchbay\Tests\TestCase;

class ApplicationFactoryTest extends TestCase
{
    protected function factory(): ApplicationFactory
    {
        return $this->app->make(ApplicationFactory::class);
    }

    protected function attributes(array $overrides = []): array
    {
        return array_merge([
            'id' => '01HZY0000000000000000000AA',
            'key' => 'test-key',
            'secret' => 'test-secret',
        ], $overrides);
    }

    public function test_it_maps_the_required_credentials(): void
    {
        $application = $this->factory()->make($this->attributes());

        $this->assertSame('01HZY0000000000000000000AA', $application->id());
        $this->assertSame('test-key', $application->key());
        $this->assertSame('test-secret', $application->secret());
    }

    public function test_it_falls_back_to_configured_defaults(): void
    {
        config()->set('patchbay.defaults.ping_interval', 45);
        config()->set('patchbay.defaults.activity_timeout', 25);
        config()->set('patchbay.defaults.allowed_origins', ['https://example.test']);

        $application = $this->factory()->make($this->attributes());

        $this->assertSame(45, $application->pingInterval());
        $this->assertSame(25, $application->activityTimeout());
        $this->assertSame(['https://example.test'], $application->allowedOrigins());
    }

    public function test_an_application_overrides_the_defaults(): void
    {
        config()->set('patchbay.defaults.ping_interval', 45);

        $application = $this->factory()->make($this->attributes(['ping_interval' => 90]));

        $this->assertSame(90, $application->pingInterval());
    }

    public function test_it_omits_rate_limiting_unless_enabled(): void
    {
        config()->set('patchbay.defaults.rate_limiting', ['enabled' => false, 'max_attempts' => 60]);

        $this->assertNull($this->factory()->make($this->attributes())->rateLimiting());

        $application = $this->factory()->make($this->attributes([
            'rate_limiting' => ['enabled' => true, 'max_attempts' => 10, 'decay_seconds' => 30],
        ]));

        $this->assertSame(10, $application->rateLimiting()['max_attempts']);
    }

    public function test_it_merges_connection_options_over_the_configured_ones(): void
    {
        config()->set('patchbay.options', ['host' => 'ws.example.test', 'port' => 443]);

        $application = $this->factory()->make($this->attributes([
            'options' => ['port' => 8080],
        ]));

        $this->assertSame('ws.example.test', $application->options()['host']);
        $this->assertSame(8080, $application->options()['port']);
    }

    /**
     * Reverb's Application constructor has gained a parameter three times —
     * maxConnections in 1.6, acceptClientEventsFrom in 1.8 and rateLimiting
     * in 1.9 — each one inserted before $options. Anything passing arguments
     * positionally shifts silently on upgrade, which is what pinned the
     * original implementation to Reverb 1.3. This asserts the parameter names
     * we pass by still exist, so a future insertion fails here rather than at
     * a customer's connection.
     */
    public function test_it_passes_every_argument_by_a_name_reverb_still_defines(): void
    {
        $parameters = collect((new ReflectionMethod(Application::class, '__construct'))->getParameters())
            ->map->getName()
            ->all();

        foreach ([
            'id', 'key', 'secret', 'pingInterval', 'activityTimeout',
            'allowedOrigins', 'maxMessageSize', 'maxConnections',
            'acceptClientEventsFrom', 'rateLimiting', 'options',
        ] as $name) {
            $this->assertContains($name, $parameters, "Reverb's Application no longer accepts \${$name}.");
        }
    }
}
