<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RobertBoes\Patchbay\EnvSnippet;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\TestCase;

class EnvSnippetTest extends TestCase
{
    use RefreshDatabase;

    protected function snippet(): string
    {
        return $this->app->make(EnvSnippet::class)->for(App::create(['name' => 'test']));
    }

    protected function setApi(string $host, int $port, string $scheme): void
    {
        config()->set('patchbay.options', ['host' => $host, 'port' => $port, 'scheme' => $scheme]);
    }

    public function test_it_includes_the_application_credentials(): void
    {
        $app = App::create(['name' => 'test']);

        $snippet = $this->app->make(EnvSnippet::class)->for($app);

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
}
