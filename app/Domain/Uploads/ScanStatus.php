<?php

namespace App\Domain\Uploads;

/**
 * Whether a stored file may be handed out. `pending` and `infected` never
 * are; `skipped` only when the deployment explicitly allows unscanned files
 * because no scanning engine exists yet.
 */
class ScanStatus
{
    public const PENDING = 'pending';

    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    public const SKIPPED = 'skipped';

    public static function servable(string $status): bool
    {
        return $status === self::CLEAN
            || ($status === self::SKIPPED && (bool) config('uploads.allow_unscanned', true));
    }
}
