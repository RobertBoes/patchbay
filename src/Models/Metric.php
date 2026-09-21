<?php

namespace RobertBoes\Patchbay\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $app_id
 * @property string|null $server
 * @property int $connections
 * @property int $channels
 * @property int $messages_sent
 * @property int $messages_received
 */
class Metric extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('patchbay.metrics.table', 'patchbay_metrics');
    }

    public function getConnectionName(): ?string
    {
        return config('patchbay.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'connections' => 'integer',
            'channels' => 'integer',
            'messages_sent' => 'integer',
            'messages_received' => 'integer',
        ];
    }
}
