<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Tests\TestCase;

class CreateAppCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'What should the application be called?';

    private const ORIGINS = 'Which origins may open a connection?';

    private const ACTIVATE = 'Activate the application now?';

    public function test_it_asks_for_everything_it_needs(): void
    {
        $this->artisan('patchbay:create-app')
            ->expectsQuestion(self::NAME, 'checkout')
            ->expectsQuestion(self::ORIGINS, 'https://example.com, https://app.example.com')
            ->expectsConfirmation(self::ACTIVATE, 'yes')
            ->assertSuccessful();

        $app = App::sole();

        $this->assertSame('checkout', $app->name);
        $this->assertSame(['https://example.com', 'https://app.example.com'], $app->allowed_origins);
        $this->assertTrue($app->active);
    }

    public function test_an_empty_origins_answer_allows_any_origin(): void
    {
        $this->artisan('patchbay:create-app')
            ->expectsQuestion(self::NAME, 'checkout')
            ->expectsQuestion(self::ORIGINS, '')
            ->expectsConfirmation(self::ACTIVATE, 'yes')
            ->assertSuccessful();

        $this->assertSame(['*'], App::sole()->allowed_origins);
    }

    public function test_declining_activation_creates_an_inactive_application(): void
    {
        $this->artisan('patchbay:create-app')
            ->expectsQuestion(self::NAME, 'checkout')
            ->expectsQuestion(self::ORIGINS, '')
            ->expectsConfirmation(self::ACTIVATE, 'no')
            ->assertSuccessful();

        $this->assertFalse(App::sole()->active);
    }

    public function test_it_asks_only_for_what_was_not_supplied(): void
    {
        $this->artisan('patchbay:create-app checkout --origins=https://example.com --inactive')
            ->assertSuccessful();

        $app = App::sole();

        $this->assertSame('checkout', $app->name);
        $this->assertSame(['https://example.com'], $app->allowed_origins);
        $this->assertFalse($app->active);
    }

    public function test_it_creates_an_application_without_asking_anything(): void
    {
        $this->artisan('patchbay:create-app', ['--no-interaction' => true])
            ->assertSuccessful();

        $app = App::sole();

        $this->assertNotEmpty($app->name);
        $this->assertSame(['*'], $app->allowed_origins);
        $this->assertTrue($app->active);
    }

    public function test_it_prints_the_environment_block_for_the_new_application(): void
    {
        $this->artisan('patchbay:create-app checkout --no-interaction')
            ->expectsOutputToContain('REVERB_APP_ID=')
            ->assertSuccessful();
    }
}
