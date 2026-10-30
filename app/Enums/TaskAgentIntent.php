<?php

namespace App\Enums;

use App\Actions\Projects\ResolveTaskAgentIntent;

/**
 * What the task panel's agent controls ask for — see
 * {@see ResolveTaskAgentIntent}.
 */
enum TaskAgentIntent: string
{
    case Assign = 'assign';
    case Start = 'start';
    case Stop = 'stop';
}
