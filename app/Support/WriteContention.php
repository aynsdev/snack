<?php

namespace App\Support;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * A write that gave up waiting for another: a lock timeout (SQLSTATE 55P03) or
 * a deadlock Postgres broke by cancelling this side (40P01).
 *
 * Nothing was changed — the transaction rolled back — and the same request a
 * moment later will almost always go through, so callers are told to retry
 * rather than shown an error.
 */
final class WriteContention
{
    public const MESSAGE = 'Snack is busy — please try again.';

    public const RETRY_AFTER_SECONDS = 2;

    public static function causedBy(Throwable $exception): bool
    {
        return $exception instanceof DeadlockException
            || ($exception instanceof QueryException && in_array($exception->getCode(), ['55P03', '40P01'], true));
    }
}
