<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | Snack is invitation-first. For internal deployments public registration is
    | disabled entirely, meaning the only path into a workspace is an invitation
    | link issued by an owner or admin.
    |
    */

    'allow_public_registration' => env('SNACK_ALLOW_PUBLIC_REGISTRATION', false),

    /*
    |--------------------------------------------------------------------------
    | Workspaces
    |--------------------------------------------------------------------------
    |
    | Defaults applied when a workspace is created. "default_channels" are
    | created automatically and every new member is joined to them.
    |
    */

    'workspaces' => [
        'member_limit' => (int) env('SNACK_DEFAULT_WORKSPACE_MEMBER_LIMIT', 500),

        'default_channels' => [
            ['name' => 'general', 'description' => 'Company-wide chatter that does not fit anywhere else.'],
            ['name' => 'announcements', 'description' => 'Important updates from the leadership team.'],
        ],

        /**
         * Reserved slugs that may never be claimed by a workspace, because they
         * would collide with first-party application routes.
         */
        'reserved_slugs' => [
            'admin', 'api', 'app', 'auth', 'broadcasting', 'dashboard', 'health',
            'invitations', 'login', 'logout', 'password', 'register', 'settings',
            'storage', 'two-factor', 'up', 'user', 'verification', 'w', 'well-known',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    */

    'invitations' => [
        'ttl_days' => (int) env('SNACK_INVITATION_TTL_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels
    |--------------------------------------------------------------------------
    */

    'channels' => [
        'name_max_length' => 80,
        'reserved_names' => ['everyone', 'here', 'channel'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | Direct and group messages. "sidebar_limit" bounds the DM list that ships
    | with every workspace page: someone with hundreds of conversations should
    | not pay for all of them on every request, and a sidebar that long is
    | navigated by search rather than by scrolling.
    |
    */

    'conversations' => [
        'sidebar_limit' => 30,
        'max_participants' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    'messages' => [
        'max_length' => 12_000,
        'page_size' => 50,

        /*
         * Threads page forward from the root rather than back from the newest
         * reply, and are read start to finish, so a page here is a chunk of a
         * conversation rather than a screenful of scrollback.
         */
        'thread_page_size' => 50,
        'edit_window_minutes' => null, // null = members may edit their messages forever.
    ],

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    |
    | Reactions are stored as shortcodes ("taco"), never as the rendered glyph,
    | so the same reaction survives an emoji set change.
    |
    | "available" is both the picker's contents and the server's allowlist. A
    | free-text shortcode column would accept anything a crafted request cared
    | to send, and every unrecognized value would render as a blank pill nobody
    | could remove. Validating against this list means every stored reaction is
    | one the client knows how to draw.
    |
    */

    'reactions' => [
        'available' => [
            'thumbsup' => '👍',
            'heart' => '❤️',
            'tada' => '🎉',
            'rocket' => '🚀',
            'eyes' => '👀',
            'joy' => '😂',
            'thinking' => '🤔',
            'fire' => '🔥',
            'clap' => '👏',
            'white_check_mark' => '✅',
            'pray' => '🙏',
            'taco' => '🌮',
        ],

        /*
         * How many reactors' names ride along on a message. The pill's tooltip
         * reads "Ada, Bo and 3 others" — past a dozen names nobody reads the
         * list, and the payload should not grow with the size of the channel.
         */
        'named_reactors' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Threads
    |--------------------------------------------------------------------------
    |
    | "list_limit" bounds the Threads view. Following a thread is implicit —
    | replying subscribes you — so an active member accumulates them quickly,
    | and the view is a digest of what just moved rather than an archive.
    |
    */

    'threads' => [
        'list_limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mentions
    |--------------------------------------------------------------------------
    |
    | "list_limit" bounds the Mentions view, which is a history of what was
    | addressed to you rather than a queue that empties — rows are stamped read,
    | never deleted, so it would otherwise grow without limit.
    |
    */

    'mentions' => [
        'list_limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Saved messages
    |--------------------------------------------------------------------------
    |
    | A private bookmark list, bounded for the same reason the others are: it
    | only ever grows, since nothing removes a bookmark except its owner.
    |
    */

    'saved' => [
        'list_limit' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Attachments are always stored on a private disk and served through signed,
    | authorization-checked routes. Never expose the underlying path.
    |
    */

    'uploads' => [
        'disk' => env('SNACK_UPLOAD_DISK', 'local'),
        'max_size_kb' => (int) env('SNACK_MAX_UPLOAD_SIZE_KB', 51_200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    |
    | Live presence is tracked in Redis / Reverb presence channels. The database
    | column "users.last_seen_at" is only persisted every N seconds so that an
    | active workspace does not generate a write per keystroke.
    |
    */

    'presence' => [
        'heartbeat_seconds' => (int) env('SNACK_PRESENCE_HEARTBEAT_SECONDS', 60),
        'typing_ttl_seconds' => 5,
        'away_after_seconds' => 300,
    ],

];
