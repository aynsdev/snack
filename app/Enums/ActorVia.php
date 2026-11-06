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

    /**
     * The person themselves, in the mobile app — a person acting exactly as
     * {@see self::Web} is, through a different door.
     */
    case Mobile = 'mobile';
    case Mcp = 'mcp';
    case Agent = 'agent';
    case Webhook = 'webhook';
    case System = 'system';

    /**
     * Whether a person typed or tapped this themselves — the browser or the
     * mobile app — as opposed to an assistant, an agent, a webhook, or Snack.
     */
    public function isPerson(): bool
    {
        return $this === self::Web || $this === self::Mobile;
    }
}
