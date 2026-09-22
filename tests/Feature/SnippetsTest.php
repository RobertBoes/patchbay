<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RobertBoes\Patchbay\Snippets;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\TestCase;

class SnippetsTest extends TestCase
{
    use RefreshDatabase;

    protected function snippet(): string
    {
        return $this->app->make(Snippets::class)->env(App::create(['name' => 'test']));
    }

    protected function setApi(string $host, int $port, string $scheme): void
    {
        config()->set('patchbay.options', ['host' => $host, 'port' => $port, 'scheme' => $scheme]);
    }

    public function test_it_includes_the_application_credentials(): void
    {
        $app = App::create(['name' => 'test']);

        $snippet = $this->app->make(Snippets::class)->env($app);

        $this->assertStringContainsString("REVERB_APP_ID={$app->id}", $snippet);
        $this->assertStringContainsString("REVERB_APP_KEY={$app->key}", $snippet);
        $this->assertStringContainsString("REVERB_APP_SECRET={$app->secret}", $snippet);
    }

    public function test_it_writes_the_server_address_from_the_application_options(): void
    {
        $this->setApi('sockets.example.test', 8080, 'http');

        $snippet = $this->snippet();

        $this->assertStringContainsString('REVERB_HOST=sockets.example.test', $snippet);
        $this->assertStringContainsString('REVERB_PORT=8080', $snippet);
        $this->assertStringContainsString('REVERB_SCHEME=http', $snippet);
    }

    public function test_the_browser_values_interpolate_the_server_ones(): void
    {
        $this->setApi('sockets.example.test', 443, 'https');

        $snippet = $this->snippet();

        $this->assertStringContainsString('VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"', $snippet);
        $this->assertStringContainsString('VITE_REVERB_HOST="${REVERB_HOST}"', $snippet);
        $this->assertStringContainsString('VITE_REVERB_PORT="${REVERB_PORT}"', $snippet);
        $this->assertStringContainsString('VITE_REVERB_SCHEME="${REVERB_SCHEME}"', $snippet);
    }

    public function test_the_browser_snippet_connects_without_the_secret(): void
    {
        $this->setApi('sockets.example.test', 443, 'https');
        $app = App::create(['name' => 'test']);

        $snippet = $this->app->make(Snippets::class)->browser($app);

        $this->assertStringContainsString("new Pusher('{$app->key}'", $snippet);
        $this->assertStringContainsString("wsHost: 'sockets.example.test'", $snippet);
        $this->assertStringContainsString('forceTLS: true', $snippet);
        $this->assertStringNotContainsString($app->secret, $snippet);
    }

    public function test_the_server_snippet_signs_with_the_secret(): void
    {
        $this->setApi('sockets.example.test', 8080, 'http');
        $app = App::create(['name' => 'test']);

        $snippet = $this->app->make(Snippets::class)->server($app);

        $this->assertStringContainsString("appId: '{$app->id}'", $snippet);
        $this->assertStringContainsString("secret: '{$app->secret}'", $snippet);
        $this->assertStringContainsString('useTLS: false', $snippet);
    }
}
