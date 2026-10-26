<?php

namespace App\Enums;

/**
 * How the change an outbound event describes reached Snack.
 *
 * A receiver uses this to decide what a change *means*: an approval typed by a
 * person in the browser is one, the same words posted by that person's agent are
 * not.
 */
enum ActorVia: string
{
    case Web = 'web';
    case Mcp = 'mcp';
    case Agent = 'agent';
    case Webhook = 'webhook';
    case System = 'system';
}
