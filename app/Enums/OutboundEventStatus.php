<?php

namespace App\Enums;

enum OutboundEventStatus: string
{
    /** Not yet accepted by the receiver; due again at `available_at`. */
    case Pending = 'pending';

    case Delivered = 'delivered';

    /** Refused or unreachable for the whole retry window. Kept for inspection. */
    case Dead = 'dead';
}
