<?php

namespace RobertBoes\Patchbay\Exceptions;

use InvalidArgumentException;

class InvalidReloadDriver extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $available
     */
    public static function notConfigured(string $driver, array $available): self
    {
        return new self(sprintf(
            'Patchbay reload driver [%s] is not configured. Add it under '
            . '`patchbay.reload.drivers`, or set `patchbay.reload.driver` to one '
            . 'of: %s. The "cache" driver needs no extra infrastructure.',
            $driver,
            implode(', ', $available) ?: 'none are configured',
        ));
    }

    public static function missingClass(string $driver): self
    {
        return new self(sprintf(
            'Patchbay reload driver [%s] has no `via` class configured. Set '
            . '`patchbay.reload.drivers.%s.via` to a class implementing %s.',
            $driver,
            $driver,
            \RobertBoes\Patchbay\Contracts\ReloadDriver::class,
        ));
    }
}
