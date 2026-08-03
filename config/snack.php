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

        // The logo is centre-cropped square and re-encoded at this edge — see
        // App\Actions\Workspaces\UpdateWorkspaceLogo for why the stored bytes
        // are never the uploaded ones.
        'logo_dimension' => 512,

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

        /*
         * Shareable invite links live longer than emailed invitations, because
         * they are used differently: an emailed invitation is aimed at one
         * person who is expected to act on it this week, while a link goes in an
         * onboarding doc or a team channel and is expected to still work for the
         * next person who reads it. Short enough that a leaked URL stops being a
         * way in without anybody noticing.
         */
        'link_ttl_days' => (int) env('SNACK_INVITE_LINK_TTL_DAYS', 30),

        /*
         * The ceiling an admin may set on one link's redemptions. Not a default
         * — a link is unlimited within its expiry unless a limit is chosen — but
         * a bound on the number the form will accept, so the column cannot be
         * handed something absurd.
         */
        'link_max_uses' => (int) env('SNACK_INVITE_LINK_MAX_USES', 1000),
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

        /*
         * How many files may ride along with one message. The composer enforces
         * the same ceiling as a courtesy; this is the authority.
         */
        'per_message' => 10,

        /*
         * A deny list rather than an allow list, and deliberately so: an allow
         * list of "documents people share at work" is a list nobody can finish,
         * and every omission reads to the user as a broken product. What must be
         * refused is the much shorter list of things a browser or an operating
         * system will *execute* — so that is what is enumerated.
         *
         * Extension and MIME type are both checked, because either alone is
         * trivially wrong: a client picks the MIME type it feels like sending,
         * and an extension is just the tail of a string.
         */
        'blocked_extensions' => [
            'app', 'bat', 'cgi', 'cmd', 'com', 'cpl', 'dll', 'exe', 'jar', 'js',
            'jse', 'msi', 'msp', 'phar', 'php', 'php3', 'php4', 'php5', 'phtml',
            'pif', 'ps1', 'py', 'reg', 'scr', 'sh', 'vb', 'vbe', 'vbs', 'wsf',
        ],

        'blocked_mime_types' => [
            'application/x-msdownload',
            'application/x-msdos-program',
            'application/x-sh',
            'application/x-httpd-php',
            'text/x-php',
        ],

        /*
         * Thumbnails are generated for images only, on the queue, at a size that
         * covers both the inline preview in the timeline and the grid tile in
         * the file browser. Bounded on the long edge so a panorama does not
         * become a 4,000-pixel-wide "thumbnail".
         */
        'thumbnail_max_edge' => 640,

        /*
         * Inline previews are rendered for images below this ceiling. Above it
         * the message shows a file chip instead: a 40 MB PNG is not a preview,
         * it is a download that happens to start on its own.
         */
        'inline_preview_max_size' => 25 * 1024 * 1024,

        /*
         * How long a download link stays valid. Long enough to click, short
         * enough that a URL pasted somewhere else stops working — the signature
         * is a convenience, not the authorization, which is re-checked on every
         * hit regardless.
         */
        'download_link_ttl_minutes' => 30,

        /*
         * Uploads that were never attached to a sent message are swept after
         * this long. Someone can legitimately leave a composer open for hours,
         * so this is generous.
         */
        'orphan_ttl_hours' => 24,

        // The "Files" browser.
        'browser_page_size' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Avatars
    |--------------------------------------------------------------------------
    |
    | Unlike attachments, an avatar is public by design: it renders next to
    | every message its owner has ever written, for everyone who can read that
    | message. Serving it through a signed, per-viewer route would mean a
    | database round trip per face on screen, so it lives on the public disk.
    |
    | It is re-encoded rather than stored as uploaded. That normalizes the
    | dimensions, strips EXIF (a photo taken on a phone carries GPS coordinates),
    | and means the bytes served are ones this application wrote.
    |
    */

    'avatars' => [
        'disk' => 'public',
        'max_size_kb' => 4096,
        'dimension' => 512,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Full-text search over "messages.search_vector", a real tsvector column
    | refreshed by a queued job because it folds in the sender's name, the
    | channel name, and attachment filenames — all of which live in other tables
    | and can change after the message is written.
    |
    */

    'search' => [
        // The text search configuration. 'english' gives stemming, so "meeting"
        // finds "meetings"; 'simple' would not.
        'dictionary' => 'english',

        'page_size' => 25,

        /*
         * The shortest query worth running lives on App\Support\SearchQuery as
         * MIN_TERM_LENGTH rather than here: it is what makes that value object's
         * `isEmpty()` answerable, and a value object that reaches into the
         * container cannot be constructed without booting the framework.
         */

        /*
         * ts_rank's weight array, which Postgres reads in the fixed order
         * {D, C, B, A} — so this is filenames, channel name, sender name, body.
         * The body dominates on purpose: a message *about* invoices should
         * outrank one that merely happens to carry a file called "invoice.pdf".
         */
        'weights' => [0.1, 0.2, 0.4, 1.0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Paginated rather than capped like the message inboxes: the audit viewer's
    | whole purpose is reaching back, so a fixed limit would defeat it.
    |
    */

    'audit' => [
        'page_size' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    |
    | How the green dot is kept honest.
    |
    | Presence is a claim with an expiry, not a flag. A browser that is closed,
    | crashed, or dropped off wifi sends nothing at all — there is no "goodbye"
    | to rely on — so a status only ever written by the client stays Online
    | forever. Every value here exists to make the claim decay:
    |
    | - "heartbeat_seconds" is how often an open tab renews it.
    | - "away_after_seconds" is when a silent tab is assumed idle rather than
    |   gone. Roughly two missed beats, so one dropped request does not flicker
    |   somebody to Away while they are typing.
    | - "offline_after_seconds" is when it is assumed gone. Deliberately several
    |   missed beats: calling somebody offline while they are reading is the
    |   error people actually notice.
    |
    | The client also reports Away directly when its tab is hidden, which is
    | faster and more accurate than any timer here. The windows are the backstop
    | for when the client cannot report at all.
    |
    | "typing_ttl_seconds" is unrelated to the decay above: it is how long a
    | typing indicator survives without a fresh whisper, mirrored by hand in
    | resources/js/hooks/use-typing.ts.
    |
    */

    'presence' => [
        'heartbeat_seconds' => (int) env('SNACK_PRESENCE_HEARTBEAT_SECONDS', 60),
        'away_after_seconds' => (int) env('SNACK_PRESENCE_AWAY_AFTER_SECONDS', 150),
        'offline_after_seconds' => (int) env('SNACK_PRESENCE_OFFLINE_AFTER_SECONDS', 300),
        'typing_ttl_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | The in-app centre is a history like Mentions and Threads, so "list_limit"
    | bounds the page rather than a queue that drains.
    |
    | "idle_minutes" is what makes an email worth sending: somebody with a live
    | socket whose tab has sat untouched since lunch is away, whatever their
    | presence status claims. Deliberately longer than
    | presence.away_after_seconds — that decides how an avatar renders, which can
    | afford to be wrong for a minute, while this decides whether to put
    | something in a person's inbox, which cannot be taken back.
    |
    | "digest" summarises what was missed. Off unless a member opts in, and sent
    | once a day at the hour below in each member's own timezone.
    |
    */

    'notifications' => [
        'list_limit' => 50,

        'idle_minutes' => (int) env('SNACK_NOTIFICATION_IDLE_MINUTES', 10),

        'digest' => [
            'hour' => (int) env('SNACK_DIGEST_HOUR', 8),

            // Nothing older than this appears in a digest. A summary that
            // reaches back further is a second inbox, not a catch-up.
            'window_hours' => 24,

            // Beyond this the digest stops listing and starts counting: an
            // email with two hundred rows in it is not read.
            'max_items' => 15,
        ],
    ],

];
