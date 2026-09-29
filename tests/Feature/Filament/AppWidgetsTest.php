<?php

namespace RobertBoes\Patchbay\Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RobertBoes\Patchbay\Filament\Widgets\AppConnectionsChart;
use RobertBoes\Patchbay\Filament\Widgets\AppStats;
use RobertBoes\Patchbay\Models\App;
use RobertBoes\Patchbay\Models\Metric;
use RobertBoes\Patchbay\Tests\FilamentTestCase;

class AppWidgetsTest extends FilamentTestCase
{
    use RefreshDatabase;

    protected function sample(
        App $app,
        int $connections = 0,
        int $sent = 0,
        int $received = 0,
        ?Carbon $at = null,
        ?string $server = null,
    ): void {
        Metric::create([
            'app_id' => $app->id,
            'server' => $server,
            'connections' => $connections,
            'channels' => 1,
            'messages_sent' => $sent,
            'messages_received' => $received,
            'recorded_at' => $at ?? Carbon::now(),
        ]);
    }

    public function test_the_stats_count_only_the_application_being_viewed(): void
    {
        $app = App::factory()->create();
        $other = App::factory()->create();

        $this->sample($app, sent: 40, received: 2);
        $this->sample($other, sent: 9_000, received: 9_000);

        Livewire::test(AppStats::class, ['record' => $app])
            ->assertSee('40')
            ->assertDontSee('9,000');
    }

    public function test_messages_are_totalled_across_the_window(): void
    {
        $app = App::factory()->create();

        $this->sample($app, sent: 10, at: Carbon::now()->subHours(2));
        $this->sample($app, sent: 15, at: Carbon::now()->subHour());

        Livewire::test(AppStats::class, ['record' => $app])->assertSee('25');
    }

    public function test_samples_older_than_the_window_are_left_out(): void
    {
        $app = App::factory()->create();

        $this->sample($app, sent: 777, at: Carbon::now()->subDays(3));

        Livewire::test(AppStats::class, ['record' => $app])->assertDontSee('777');
    }

    public function test_peak_connections_is_the_fleet_total_at_a_moment(): void
    {
        $app = App::factory()->create();
        $at = Carbon::now()->subHour();

        // Two servers holding 6 and 7 at the same moment peaks at 13, not 7.
        $this->sample($app, connections: 6, at: $at, server: 'one');
        $this->sample($app, connections: 7, at: $at, server: 'two');
        $this->sample($app, connections: 9, at: Carbon::now(), server: 'one');

        Livewire::test(AppStats::class, ['record' => $app])->assertSee('13');
    }

    public function test_the_stats_hold_their_shape_before_the_first_sample(): void
    {
        $app = App::factory()->create();

        Livewire::test(AppStats::class, ['record' => $app])
            ->assertOk()
            ->assertSee('—');
    }

    public function test_the_chart_is_scoped_to_the_application(): void
    {
        $app = App::factory()->create();
        $other = App::factory()->create();
        $at = Carbon::now()->subHour();

        $this->sample($app, connections: 4, at: $at);
        $this->sample($other, connections: 11, at: $at);

        // getData() is protected, and the chart renders its payload through
        // Livewire's lazy load rather than the initial response.
        $chart = new class extends AppConnectionsChart {
            public function data(): array
            {
                return $this->getData();
            }
        };

        $chart->record = $app;

        $this->assertSame([4], $chart->data()['datasets'][0]['data']);

        Livewire::test(AppConnectionsChart::class, ['record' => $app])->assertOk();
    }

    public function test_the_widgets_are_hidden_when_metrics_are_disabled(): void
    {
        config()->set('patchbay.metrics.enabled', false);

        $this->assertFalse(AppStats::canView());
        $this->assertFalse(AppConnectionsChart::canView());
    }
}
