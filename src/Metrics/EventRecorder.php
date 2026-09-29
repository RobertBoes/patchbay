<?php

namespace RobertBoes\Patchbay\Metrics;

use Illuminate\Support\Carbon;
use Laravel\Reverb\Application;
use Laravel\Reverb\Loggers\Log;
use RobertBoes\Patchbay\Models\Event;

/**
 * Keeps the last events an application carried, so a dashboard can answer
 * what went out and what came back rather than only how much did.
 *
 * Events are buffered and written in one statement on the same timer as
 * metrics: a write per message would put the database in the path of every
 * frame the server handles. The buffer is capped, so a burst costs a bounded
 * amount of memory and drops the oldest rather than growing without end.
 */
class EventRecorder
{
    /** @var array<int, array<string, mixed>> */
    protected array $buffered = [];

    public function __construct(
        protected string $model,
        protected ?string $server = null,
        protected int $limit = 500,
        protected int $payloadLength = 1000,
        protected bool $recordsPayloads = true,
    ) {
        //
    }

    public function sent(Application $application, string $message): void
    {
        $this->record($application, Event::SENT, $message);
    }

    public function received(Application $application, string $message): void
    {
        $this->record($application, Event::RECEIVED, $message);
    }

    protected function record(Application $application, string $direction, string $message): void
    {
        $decoded = json_decode($message, associative: true);

        if (! is_array($decoded) || ! is_string($event = $decoded['event'] ?? null)) {
            return;
        }

        $this->buffered[] = [
            'app_id' => $application->id(),
            'server' => $this->server,
            'direction' => $direction,
            'event' => mb_substr($event, 0, 255),
            'channel' => is_string($channel = $decoded['channel'] ?? null)
                ? mb_substr($channel, 0, 255)
                : null,
            'payload' => $this->payload($decoded),
            'recorded_at' => Carbon::now(),
        ];

        // Dropping the oldest keeps a burst from growing the buffer without
        // end; the rows that survive are the ones nearest to now.
        if (count($this->buffered) > $this->limit) {
            array_shift($this->buffered);
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    protected function payload(array $decoded): ?string
    {
        if (! $this->recordsPayloads || ! array_key_exists('data', $decoded)) {
            return null;
        }

        $data = is_string($decoded['data']) ? $decoded['data'] : json_encode($decoded['data']);

        if (! is_string($data)) {
            return null;
        }

        return mb_substr($data, 0, $this->payloadLength);
    }

    public function flush(): int
    {
        if ($this->buffered === []) {
            return 0;
        }

        $rows = $this->buffered;
        $this->buffered = [];

        $this->model::query()->insert($rows);

        Log::info('Patchbay Events Recorded', (string) count($rows));

        return count($rows);
    }
}
