<?php

namespace RobertBoes\Patchbay\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\RateLimiter;
use RobertBoes\Patchbay\Internal\InternalApp;

/**
 * Holds the panel's own connection to the server, so figures follow what the
 * server is doing rather than a timer.
 *
 * The server pushes a reading only when it differs from the last one, and
 * this asks the page to re-read itself when one arrives. Nothing is rendered
 * from the pushed figures: every widget keeps drawing from the source it
 * already had, so there is one way for a number to reach the screen rather
 * than two that can disagree.
 */
class LiveUpdates extends Widget
{
    /** Signatures allowed per user, per minute. */
    public const AUTHORIZATIONS_PER_MINUTE = 20;

    protected string $view = 'patchbay::filament.widgets.live-updates';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return app(InternalApp::class)->enabled();
    }

    /**
     * Signs this page's subscription to the internal channel.
     *
     * The channel is private and carries readings for every application, so
     * it is signed for signed-in users only. It reports what the server is
     * holding, not anything an application sent.
     *
     * @return array{auth: string}|null
     */
    public function authorizeChannel(string $socketId, string $channel): ?array
    {
        if (! preg_match('/^\d+\.\d+$/', $socketId) || $channel !== InternalApp::CHANNEL) {
            return null;
        }

        if (! auth()->check()) {
            return null;
        }

        $limiter = 'patchbay-internal-auth:' . auth()->id();

        if (RateLimiter::tooManyAttempts($limiter, self::AUTHORIZATIONS_PER_MINUTE)) {
            return null;
        }

        RateLimiter::hit($limiter, 60);

        $internal = app(InternalApp::class);

        return [
            'auth' => $internal->key() . ':' . hash_hmac(
                'sha256',
                "{$socketId}:{$channel}",
                $internal->secret(),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $internal = app(InternalApp::class);
        $options = (array) config('patchbay.options', []);

        return [
            'client' => [
                'key' => $internal->key(),
                'channel' => InternalApp::CHANNEL,
                'host' => (string) ($options['host'] ?? '127.0.0.1'),
                'port' => (int) ($options['port'] ?? 443),
                'tls' => ($options['scheme'] ?? 'https') === 'https',
            ],
        ];
    }
}
