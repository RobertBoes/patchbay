<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use React\EventLoop\Loop;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Registry;
use RobertBoes\Patchbay\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ServerStartTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The null driver registers no timers, so the loop empties and run()
        // returns instead of servicing a periodic reload forever.
        $app['config']->set('patchbay.reload.driver', 'null');
    }

    protected function startServer(): void
    {
        event(new CommandStarting('reverb:start', new ArrayInput([]), new NullOutput()));
    }

    public function test_nothing_is_loaded_while_the_command_is_still_starting(): void
    {
        App::create(['name' => 'test']);

        $this->startServer();

        $this->assertSame(
            0,
            $this->app->make(Registry::class)->count(),
            'Loading during CommandStarting happens before reverb:start installs its '
            . 'logger, and Reverb memoises the first logger it resolves into a static '
            . 'property — which would silence the whole server\'s --debug output.',
        );
    }

    public function test_applications_are_loaded_on_the_first_tick_of_the_loop(): void
    {
        App::factory()->count(3)->create();

        $this->startServer();

        $loop = Loop::get();
        $loop->futureTick(fn() => $loop->stop());
        $loop->run();

        $this->assertSame(3, $this->app->make(Registry::class)->count());
    }

    public function test_it_ignores_other_commands(): void
    {
        App::create(['name' => 'test']);

        event(new CommandStarting('migrate', new ArrayInput([]), new NullOutput()));

        $loop = Loop::get();
        $loop->futureTick(fn() => $loop->stop());
        $loop->run();

        $this->assertSame(0, $this->app->make(Registry::class)->count());
    }
}
