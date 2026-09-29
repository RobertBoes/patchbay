<?php

namespace RobertBoes\Patchbay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One event the server carried, kept so a dashboard can answer what went out
 * and what came back rather than only how much did.
 *
 * @property string $app_id
 * @property string|null $server
 * @property string $direction
 * @property string $event
 * @property string|null $channel
 * @property string|null $payload
 */
class Event extends Model
{
    public const SENT = 'sent';

    public const RECEIVED = 'received';

    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('patchbay.events.table', 'patchbay_events');
    }

    public function getConnectionName(): ?string
    {
        return config('patchbay.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function wasSent(): bool
    {
        return $this->direction === self::SENT;
    }
}
