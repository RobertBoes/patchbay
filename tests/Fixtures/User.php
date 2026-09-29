<?php

namespace RobertBoes\Patchbay\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    protected $table = 'users';

    protected $guarded = [];

    // Filament grants panel access automatically only in the local environment.
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
