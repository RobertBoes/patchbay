<?php

namespace RobertBoes\Patchbay\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RobertBoes\Patchbay\Observers\AppObserver;

/**
 * @property string $id
 * @property string $name
 * @property string $key
 * @property string $secret
 * @property array|null $allowed_origins
 * @property bool $active
 */
class App extends Model
{
    use HasFactory;
    use HasUlids;

    /** Explicit rather than guarded: `key`, `secret` and `active` must not be request-writable. */
    protected $fillable = [
        'name',
        'active',
        'allowed_origins',
        'ping_interval',
        'activity_timeout',
        'max_message_size',
        'max_connections',
        'accept_client_events_from',
        'rate_limiting',
    ];

    /** Set on the model as well as the column, or a new application reads back null. */
    protected $attributes = [
        'active' => true,
    ];

    protected $hidden = ['secret'];

    public function getTable(): string
    {
        return config('patchbay.table', 'patchbay_apps');
    }

    public function getConnectionName(): ?string
    {
        return config('patchbay.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'allowed_origins' => 'array',
            'rate_limiting' => 'array',
            'active' => 'boolean',
            'ping_interval' => 'integer',
            'activity_timeout' => 'integer',
            'max_message_size' => 'integer',
            'max_connections' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::observe(AppObserver::class);
    }

    public static function generateKey(): string
    {
        return Str::random(20);
    }

    public static function generateSecret(): string
    {
        return Str::random(40);
    }

    public static function suggestName(): string
    {
        $adjectives = ['calm', 'bright', 'quiet', 'swift', 'warm', 'bold', 'clear'];
        $nouns = ['harbour', 'signal', 'meadow', 'beacon', 'river', 'summit', 'anchor'];

        return $adjectives[array_rand($adjectives)] . '-' . $nouns[array_rand($nouns)];
    }

    protected static function newFactory(): Factory
    {
        return \RobertBoes\Patchbay\Database\Factories\AppFactory::new();
    }
}
