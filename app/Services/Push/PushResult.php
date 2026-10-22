<?php

namespace App\Services\Push;

/**
 * How one delivery to one device went.
 *
 * `InvalidToken` is the case that matters: the app was uninstalled or the
 * token rotated, and the device row should go so it is not retried forever.
 * A `Failed` delivery is FCM having a bad moment, and the row is kept.
 */
enum PushResult
{
    case Sent;
    case InvalidToken;
    case Failed;
}
