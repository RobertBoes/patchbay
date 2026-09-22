<?php

namespace RobertBoes\Patchbay\Tests\Fixtures;

use Illuminate\Auth\Access\Response;

/** Allows creating until a test sets a reason to refuse. */
class FlippableAppPolicy
{
    public static ?string $refusal = null;

    public function create(): Response
    {
        return static::$refusal === null ? Response::allow() : Response::deny(static::$refusal);
    }
}
