<?php

namespace RobertBoes\Patchbay\Server;

final class AppMetrics
{
    /**
     * @param  array<string, mixed>  $channels
     */
    public function __construct(
        public readonly bool $available,
        public readonly int $connections = 0,
        public readonly array $channels = [],
    ) {
        //
    }

    public static function unavailable(): self
    {
        return new self(available: false);
    }

    /**
     * @return array<int, string>
     */
    public function channelNames(): array
    {
        return array_keys($this->channels);
    }

    public function channelCount(): int
    {
        return count($this->channels);
    }

    /**
     * How many are on a channel. Presence channels count people, under
     * user_count, and every other kind counts sockets, under
     * subscription_count; a server asked for neither reports null.
     */
    public function subscribersFor(string $channel): ?int
    {
        $info = $this->channels[$channel] ?? [];

        if (! is_array($info)) {
            return null;
        }

        $count = $info['user_count'] ?? $info['subscription_count'] ?? null;

        return is_int($count) || is_numeric($count) ? (int) $count : null;
    }

    public function isPresenceChannel(string $channel): bool
    {
        return str_starts_with($channel, 'presence-');
    }

    /**
     * Every open channel with the number on it, ordered by the busiest.
     *
     * @return array<int, array{name: string, subscribers: int|null, presence: bool}>
     */
    public function channelSummary(): array
    {
        $summary = array_map(fn(string $name) => [
            'name' => $name,
            'subscribers' => $this->subscribersFor($name),
            'presence' => $this->isPresenceChannel($name),
        ], $this->channelNames());

        usort($summary, fn(array $a, array $b) => ($b['subscribers'] ?? -1) <=> ($a['subscribers'] ?? -1)
            ?: strcmp($a['name'], $b['name']));

        return $summary;
    }
}
