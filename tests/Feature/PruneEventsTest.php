<?php

namespace RobertBoes\Patchbay\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RobertBoes\Patchbay\Models\Event;
use RobertBoes\Patchbay\Tests\TestCase;

class PruneEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function event(string $name, Carbon $at): void
    {
        Event::create([
            'app_id' => '01m2sxpmj9wvk8jfmbwjq7k1kc',
            'direction' => Event::SENT,
            'event' => $name,
            'recorded_at' => $at,
        ]);
    }

    public function test_it_removes_events_past_the_retention(): void
    {
        config()->set('patchbay.events.retain_days', 1);

        $this->event('old', Carbon::now()->subDays(3));
        $this->event('recent', Carbon::now()->subMinutes(5));

        $this->artisan('patchbay:prune-events')->assertSuccessful();

        $this->assertSame(['recent'], Event::query()->pluck('event')->all());
    }

    public function test_the_retention_can_be_overridden(): void
    {
        config()->set('patchbay.events.retain_days', 30);

        $this->event('old', Carbon::now()->subDays(3));

        $this->artisan('patchbay:prune-events', ['--days' => 1])->assertSuccessful();

        $this->assertSame(0, Event::query()->count());
    }

    public function test_nothing_is_pruned_when_retention_is_disabled(): void
    {
        config()->set('patchbay.events.retain_days', null);

        $this->event('old', Carbon::now()->subYears(2));

        $this->artisan('patchbay:prune-events')->assertSuccessful();

        $this->assertSame(1, Event::query()->count());
    }
}
