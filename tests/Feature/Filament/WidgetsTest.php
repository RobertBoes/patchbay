<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Widgets\ConnectionsChart;
use RobertBoes\Patchbay\Filament\Widgets\ServerStatus;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class WidgetsTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 200)]);
    }

    protected function sample(App $app, int $connections, Carbon $at, ?string $server = null): void
    {
        Metric::create([
            'app_id' => $app->id,
            'server' => $server,
            'connections' => $connections,
            'channels' => 1,
            'messages_sent' => 2,
            'messages_received' => 3,
            'recorded_at' => $at,
        ]);
    }

    public function test_the_status_widget_renders(): void
    {
        App::factory()->count(2)->create();

        Livewire::test(ServerStatus::class)->assertOk();
    }

    /**
     * A widget polls, so gathering the figure per application would cost two
     * HTTP requests per application on every tick.
     */
    public function test_the_connection_count_costs_no_request_per_application(): void
    {
        $apps = App::factory()->count(20)->create();
        $at = Carbon::now();

        foreach ($apps as $app) {
            $this->sample($app, 3, $at);
        }

        Livewire::test(ServerStatus::class)->assertSee('60');

        // Only the health check, whatever the number of applications.
        Http::assertSentCount(1);
    }

    /**
     * Each server records on its own timer, so their samples never share a
     * timestamp. Counting one timestamp would report a single server's figure
     * as though it were the whole fleet's.
     */
    public function test_it_sums_the_latest_sample_from_every_server(): void
    {
        $app = App::factory()->create();

        $this->sample($app, 5, Carbon::now()->subSeconds(90), server: 'reverb-1');
        $this->sample($app, 7, Carbon::now()->subSeconds(30), server: 'reverb-1');

        $this->sample($app, 3, Carbon::now()->subSeconds(80), server: 'reverb-2');
        $this->sample($app, 4, Carbon::now()->subSeconds(20), server: 'reverb-2');

        Livewire::test(ServerStatus::class)
            ->assertSee('11')
            ->assertSee('across 2 servers');
    }

    public function test_a_single_server_is_not_described_as_a_fleet(): void
    {
        $app = App::factory()->create();

        $this->sample($app, 6, Carbon::now(), server: 'reverb-1');

        Livewire::test(ServerStatus::class)
            ->assertSee('6')
            ->assertDontSee('across');
    }

    public function test_it_says_when_there_is_no_sample_yet(): void
    {
        App::factory()->create();

        Livewire::test(ServerStatus::class)->assertSee('Waiting for the first sample');
    }

    public function test_it_says_when_metrics_are_disabled(): void
    {
        config()->set('patchbay.metrics.enabled', false);

        Livewire::test(ServerStatus::class)->assertSee('Metrics are disabled');
    }

    public function test_the_chart_renders_recorded_samples(): void
    {
        $app = App::factory()->create();
        $this->sample($app, 5, Carbon::now()->subMinutes(2));
        $this->sample($app, 8, Carbon::now()->subMinute());

        Livewire::test(ConnectionsChart::class)->assertOk();
    }

    public function test_the_chart_reads_the_database_once(): void
    {
        $apps = App::factory()->count(10)->create();

        foreach ($apps as $app) {
            $this->sample($app, 2, Carbon::now());
        }

        DB::enableQueryLog();
        Livewire::test(ConnectionsChart::class)->assertOk();

        $this->assertLessThanOrEqual(
            2,
            count(DB::getQueryLog()),
            'The chart must not scale its queries with the number of applications.',
        );
    }

    public function test_the_chart_survives_having_no_samples(): void
    {
        Livewire::test(ConnectionsChart::class)->assertOk();
    }
}
