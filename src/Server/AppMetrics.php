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
}
