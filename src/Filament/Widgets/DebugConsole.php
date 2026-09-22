<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use RobertBoes\Patchbay\Contracts\AppSource;
use RobertBoes\Patchbay\Server\ServerApi;

/**
 * Send an event to one of the application's channels and watch it arrive,
 * so a new application can be tried before anything is wired up to it.
 */
class DebugConsole extends Widget
{
    /** Sends allowed per user and application, per minute. */
    public const SENDS_PER_MINUTE = 10;

    protected string $view = 'patchbay::filament.widgets.debug-console';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public string $channel = 'test';

    public string $event = 'test-event';

    public string $payload = "{\n    \"message\": \"Hello from Patchbay\"\n}";

    public function send(): void
    {
        $this->validate([
            // Pusher's own channel name rules.
            'channel' => ['required', 'string', 'max:164', 'regex:/^[A-Za-z0-9_\-=@,.;]+$/'],
            'event' => ['required', 'string', 'max:200'],
            // Pusher's own limit for an event's data.
            'payload' => ['required', 'json', 'max:10240'],
        ]);

        $data = json_decode($this->payload, true);

        if (! is_array($data)) {
            $this->addError('payload', __('The payload must be a JSON object or array.'));

            return;
        }

        $limiter = 'patchbay-console:' . auth()->id() . ':' . $this->record->getKey();

        if (RateLimiter::tooManyAttempts($limiter, self::SENDS_PER_MINUTE)) {
            $this->notify(__('Slow down: try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($limiter),
            ]), 'warning');

            return;
        }

        RateLimiter::hit($limiter, 60);

        if (! $application = app(AppSource::class)->loadById($this->record->getKey())) {
            $this->notify(__('Activate the application to send events.'), 'warning');

            return;
        }

        app(ServerApi::class)->trigger($application, $this->channel, $this->event, $data)
            ? $this->notify(__('Sent :event to :channel.', ['event' => $this->event, 'channel' => $this->channel]), 'success')
            : $this->notify(__('The server did not accept the event.'), 'danger');
    }

    /**
     * Straight to this page rather than through the session, which the
     * widgets polling beside this one could consume first.
     */
    protected function notify(string $title, string $status): void
    {
        $this->dispatch('notificationSent', notification: Notification::make()
            ->title($title)
            ->status($status)
            ->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $options = (array) config('patchbay.options', []);

        return [
            'client' => [
                'key' => $this->record->key,
                'host' => (string) ($options['host'] ?? '127.0.0.1'),
                'port' => (int) ($options['port'] ?? 443),
                'tls' => ($options['scheme'] ?? 'https') === 'https',
            ],
            'active' => (bool) $this->record->active,
        ];
    }
}
