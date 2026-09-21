<?php

namespace RobertBoes\Patchbay\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A panel operator.
 *
 * Filament grants panel access automatically only in the local environment,
 * and the test environment is not local — so without this every request to
 * the panel would be a 403 and the tests would prove nothing.
 */
class User extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
