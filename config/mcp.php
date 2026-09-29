<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    /*
     * Not "*": a registered client's name is shown on Snack's consent screen,
     * and allowing any redirect would let any site register itself as
     * "Claude" and ask members to hand it their workspace. The clients Snack
     * is meant to be connected to, plus loopback for desktop tools like
     * Claude Code and the MCP Inspector. Add more with MCP_REDIRECT_DOMAINS.
     */
    'redirect_domains' => array_values(array_filter([
        'https://claude.ai',
        'https://claude.com',
        'http://localhost',
        'http://127.0.0.1',
        ...explode(',', (string) env('MCP_REDIRECT_DOMAINS', '')),
    ])),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        'cursor',
        'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

];
