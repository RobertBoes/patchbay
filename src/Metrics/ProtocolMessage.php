<?php

namespace RobertBoes\Patchbay\Metrics;

/**
 * Recognises the messages the Pusher protocol sends to run itself, so traffic
 * counts describe what an application carried rather than what it cost to
 * carry it.
 *
 * Connecting and subscribing is several messages before anything useful
 * happens - `pusher:connection_established`, `pusher:subscribe`,
 * `pusher_internal:subscription_succeeded` - and a browser, which cannot send
 * WebSocket control frames, is then kept alive with `pusher:ping` and answers
 * `pusher:pong`. All of it travels the same path as real events. Counted, a
 * connection that has never carried anything still reports traffic.
 *
 * What is left is what an application actually sent: its own broadcasts, and
 * the `client-` events connections send each other.
 */
final class ProtocolMessage
{
    private const PREFIXES = ['pusher:', 'pusher_internal:'];

    public static function matches(string $message): bool
    {
        // This runs for every frame the server handles, so reject the ones
        // that cannot match before paying for decoding.
        if (! str_contains($message, 'pusher')) {
            return false;
        }

        $decoded = json_decode($message, associative: true);

        if (! is_array($decoded) || ! is_string($event = $decoded['event'] ?? null)) {
            return false;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($event, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
