<?php

namespace App\Enums;

/**
 * What a personal connector token is for.
 *
 * Both kinds reach the MCP server with the same rights — the owner's. The kind
 * decides only how their actions are attributed: see {@see ActorVia}.
 */
enum ConnectorKind: string
{
    /** An assistant the person drives themselves — Cursor, Claude Code. */
    case Token = 'token';

    /** An agent that picks up tickets and works them on the person's behalf. */
    case Agent = 'agent';
}
