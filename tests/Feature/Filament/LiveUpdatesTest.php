<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Widgets\LiveUpdates;
use RobertBoes\Patchbay\Internal\InternalApp;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class LiveUpdatesTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function widget(): LiveUpdates
    {
        return Livewire::test(LiveUpdates::class)->instance();
    }

    public function test_it_signs_the_internal_channel_the_way_the_server_checks_it(): void
    {
        $internal = $this->app->make(InternalApp::class);

        $auth = $this->widget()->authorizeChannel('123.456', InternalApp::CHANNEL);

        $this->assertSame(
            $internal->key() . ':' . hash_hmac('sha256', '123.456:' . InternalApp::CHANNEL, $internal->secret()),
            $auth['auth'],
        );
    }

    public function test_it_signs_for_nothing_but_its_own_channel(): void
    {
        // The signature is the internal application's, so it must not be
        // obtainable for any other channel on it.
        $this->assertNull($this->widget()->authorizeChannel('123.456', 'private-anything'));
        $this->assertNull($this->widget()->authorizeChannel('123.456', 'private-patchbay-other'));
    }

    public function test_a_socket_id_that_is_not_one_is_refused(): void
    {
        $this->assertNull($this->widget()->authorizeChannel('nope', InternalApp::CHANNEL));
    }

    public function test_signing_is_rate_limited(): void
    {
        foreach (range(1, LiveUpdates::AUTHORIZATIONS_PER_MINUTE) as $ignored) {
            $this->assertNotNull($this->widget()->authorizeChannel('123.456', InternalApp::CHANNEL));
        }

        $this->assertNull($this->widget()->authorizeChannel('123.456', InternalApp::CHANNEL));
    }

    public function test_it_is_hidden_when_the_internal_application_is_off(): void
    {
        config()->set('patchbay.internal.enabled', false);

        $this->assertFalse(LiveUpdates::canView());
    }

    public function test_it_renders_without_the_secret(): void
    {
        $internal = $this->app->make(InternalApp::class);

        Livewire::test(LiveUpdates::class)
            ->assertOk()
            ->assertSee($internal->key())
            ->assertDontSee($internal->secret());
    }
}
