<?php

namespace RobertBoes\Patchbay\Server;

enum Health: string
{
    /** Answering, and serving the applications it should. */
    case Operational = 'operational';

    /** Answering, but not reporting in, or serving none of the active applications. */
    case Degraded = 'degraded';

    /** Not answering at all. */
    case Down = 'down';

    public function label(): string
    {
        return match ($this) {
            self::Operational => __('Operational'),
            self::Degraded => __('Degraded'),
            self::Down => __('Unreachable'),
        };
    }

    /** A Filament color name. */
    public function color(): string
    {
        return match ($this) {
            self::Operational => 'success',
            self::Degraded => 'warning',
            self::Down => 'danger',
        };
    }
}
