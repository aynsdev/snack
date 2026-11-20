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
    | Legal
    |--------------------------------------------------------------------------
    |
    | Who operates this installation, as quoted by the public terms, privacy
    | policy and account deletion pages. Every installation has a different
    | operator, so set these before pointing anyone at those pages.
    |
    */

    'legal' => [
        'company' => env('SNACK_LEGAL_COMPANY', env('APP_NAME', 'Snack')),
        'country' => env('SNACK_LEGAL_COUNTRY', 'the country where the operator is based'),
        'contact_email' => env('SNACK_LEGAL_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'privacy@example.com')),
    ],

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

    // Handles that mean something other than a person when written as @name.
    'users' => [
        'reserved_usernames' => ['agent', 'everyone', 'here'],
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
    | "people_limit" bounds the roster the DM list is built from. The sidebar
    | offers everyone in the workspace, not only the people already messaged, so
    | this is the point at which that stops being a list and starts being a
    | directory — past it, the new-message picker's search is the way in.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Activity
    |--------------------------------------------------------------------------
    |
    | The tabbed view over notifications, mentions, and followed threads.
    | "merged_limit" bounds each list's contribution to the All tab: three full
    | pages interleaved would bury whichever kind was quietest under whichever
    | was busiest, and the tabs themselves are where you go to read one of them
    | to the end.
    |
    */

    'activity' => [
        'merged_limit' => 20,
    ],

    'conversations' => [
        'sidebar_limit' => 30,
        'people_limit' => 100,
        'max_participants' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Huddles
    |--------------------------------------------------------------------------
    |
    | Live audio and video attached to a channel or a DM. The media itself is
    | carried by a self-hosted LiveKit server (config/services.php); everything
    | here is the product's own shape around it.
    |
    | A huddle is deliberately not a "meeting": there is nothing to schedule, no
    | invitation, and no separate URL. It starts where the conversation already
    | is, anyone who can post in that container can walk into it, and it ends by
    | itself when the last person leaves.
    |
    */

    'huddles' => [
        /*
         * Whether the feature exists at all in this deployment.
         *
         * Derived rather than configured: a media server is separate
         * infrastructure, and an installation that has not stood one up should
         * not render a button whose only possible outcome is a failed dial. The
         * secret is the one checked because a URL has a working default while a
         * secret never can.
         */
        'enabled' => env('LIVEKIT_API_SECRET') !== null,

        /*
         * How long a minted join token stays valid.
         *
         * This bounds the *connection*, not the conversation: LiveKit checks the
         * token when a client dials and again when it re-dials after a network
         * drop, but never mid-session. So this must comfortably outlast a real
         * huddle — a two-hour call that loses wifi on a token that expired
         * ninety minutes ago cannot rejoin, and to the person in it that reads
         * as the product dropping them for no reason. Matches the LiveKit SDKs'
         * own default for the same reason they chose it.
         */
        'token_ttl_minutes' => (int) env('SNACK_HUDDLE_TOKEN_TTL_MINUTES', 360),

        /*
         * The ceiling on one huddle. Well below what LiveKit will carry — this
         * is a product limit, not a capacity one. Past a couple of dozen people
         * nobody is talking to anybody, and the honest answer is a meeting with
         * an agenda rather than a huddle.
         */
        'max_participants' => (int) env('SNACK_HUDDLE_MAX_PARTICIPANTS', 25),

        /*
         * How long a huddle may sit with nobody in it before the sweep closes
         * it.
         *
         * Leaving is announced by the client, and like every other client
         * announcement it cannot be relied on — a closed laptop sends nothing.
         * Without this, one crashed tab leaves a huddle showing "1 person" in a
         * channel header forever, and the next person to click Join lands in an
         * empty room wondering where everybody went. Short, because an empty
         * huddle has no reason to exist; not zero, because somebody refreshing
         * the page is briefly an empty huddle too.
         */
        'empty_grace_seconds' => (int) env('SNACK_HUDDLE_EMPTY_GRACE_SECONDS', 60),

        /*
         * How many past huddles the history view lists. A history answers "did
         * I miss one, and who was on it" — a question about the last day or two,
         * not about the last year — so it is a page rather than an archive.
         */
        'history_limit' => 50,
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
    | Incoming webhooks
    |--------------------------------------------------------------------------
    |
    | A URL an outside system posts JSON to, which becomes a message in one
    | channel. See the incoming_webhooks migration for why the URL is the whole
    | credential and the destination channel is a column rather than a field a
    | caller may set.
    |
    | The limits below bound the *payload*, which arrives unauthenticated by any
    | user and is therefore the one input in Snack nobody is accountable for.
    | They are deliberately much tighter than a person's own message: a deploy
    | notification that needs twelve thousand characters is a link to a build
    | log, and a card with forty fields is a table nobody reads in a chat window.
    |
    */

    'webhooks' => [
        /*
         * The `text` ceiling. Well under `messages.max_length` because the two
         * are different things: that one is what a person can be bothered to
         * type, this one is what a script will happily send in a loop.
         */
        'max_text_length' => 4_000,

        /*
         * Attachment cards per delivery, and the fields inside one. Slack's
         * legacy attachment shape, which is what almost every existing
         * integration already knows how to build.
         */
        'max_attachments' => 10,
        'max_fields' => 20,

        /*
         * Deliveries per minute, per hook. A build pipeline posts a handful; a
         * runaway retry loop posts thousands, into a channel real people are
         * trying to read. Per hook rather than per IP: hooks behind one CI
         * provider share an address, and one team's broken job must not throttle
         * another team's.
         */
        'rate_limit_per_minute' => (int) env('SNACK_WEBHOOK_RATE_LIMIT', 60),

        /*
         * How far a signed delivery's timestamp may be from the server's clock,
         * for hooks that have a signing secret. The timestamp is inside the
         * signed string, so this is what bounds how long a captured request
         * stays replayable — see App\Services\WebhookSignature.
         *
         * Generous enough to survive a build server whose NTP has drifted,
         * short enough that a request lifted from a CI log is useless by the
         * time anybody reads it.
         */
        'signature_tolerance_seconds' => (int) env('SNACK_WEBHOOK_SIGNATURE_TOLERANCE', 300),
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
         * How long the bucket URL an inline video player is redirected to stays
         * valid. Long enough to watch a recording end to end — the player keeps
         * seeking against it — and nothing more.
         */
        'stream_url_ttl_minutes' => 120,

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
    | Asset library
    |--------------------------------------------------------------------------
    |
    | Brand kits, company information, contracts — the things a workspace files
    | on purpose, as opposed to the things it happens to have attached to a
    | message. The bytes are ordinary attachments on the same private disk and
    | obey every limit under "uploads" above; what is configured here is the
    | shape of the library around them.
    |
    | The nesting bound lives on App\Models\AssetFolder as MAX_DEPTH rather than
    | here, for the reason SearchQuery::MIN_TERM_LENGTH does: the model's own
    | tree walks are written against it, and a value they had to reach into the
    | container for could not be unit-tested.
    |
    */

    'assets' => [
        /*
         * Assets per page of one shelf. Larger than a list would need because
         * the default view is a gallery of thumbnails — a screen holds far more
         * tiles than rows, and a "load more" button two scrolls down reads as
         * the library being smaller than it is.
         */
        'page_size' => 60,
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
        /*
         * Configurable so a deployment can move faces onto object storage and a
         * CDN — `spaces_public` in production, the local `public` disk in
         * development. Read through this key on *both* sides: the actions that
         * write an avatar and the accessors that build its URL
         * ({@see App\Models\User::avatarUrl()},
         * {@see App\Models\Workspace::logoUrl()}). They used to disagree — the
         * write honoured this setting and the read hardcoded `public` — which
         * meant changing it silently pointed every avatar in the product at a
         * disk the bytes were not on.
         */
        'disk' => env('SNACK_AVATAR_DISK', 'public'),
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

        // The Unread tab's ceiling. Higher than the history's, because every
        // row the badge counts should be reachable from the tab it points at.
        'unread_limit' => 200,

        'idle_minutes' => (int) env('SNACK_NOTIFICATION_IDLE_MINUTES', 10),

        // How long an unread direct-message row keeps absorbing the ones that
        // follow it — see NotificationReason::coalescesWhileUnread().
        //
        // Bounded rather than open-ended on purpose. Folding only while the row
        // is unread would mean somebody who never opens their notification
        // centre eventually stops being emailed about direct messages at all,
        // because one ancient row would keep swallowing every new conversation.
        // A window makes the worst case "one row and one email per hour per
        // conversation" instead of "silence".
        'coalesce_minutes' => (int) env('SNACK_NOTIFICATION_COALESCE_MINUTES', 60),

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

    /*
    |--------------------------------------------------------------------------
    | Standups
    |--------------------------------------------------------------------------
    |
    | The bot that asks a channel's members a set of questions on a schedule and
    | posts their answers back to the channel.
    |
    */

    'standups' => [
        /*
         * How far past its scheduled time a standup may still be asked.
         *
         * The scheduler wakes every minute, but minutes get missed — a worker
         * restart, a deploy, a machine that was asleep — and a standup that
         * only happens if the clock is looked at during the exact minute it was
         * due is one that silently does not happen.
         *
         * The bound is what stops the catch-up being worse than the miss:
         * without it, a deployment that was down overnight would come back up
         * and DM everybody about a standup whose day is over, and switching a
         * standup on in the afternoon would immediately fire that morning's.
         */
        'catch_up_minutes' => (int) env('SNACK_STANDUP_CATCH_UP_MINUTES', 60),

        // The default answering window for a new standup. Long enough for a
        // team spread over a few hours; short enough that the write-up still
        // lands on the morning it describes.
        'closes_after_minutes' => (int) env('SNACK_STANDUP_CLOSES_AFTER_MINUTES', 240),

        // The default nudge, measured from when the questions went out.
        'reminder_after_minutes' => (int) env('SNACK_STANDUP_REMINDER_AFTER_MINUTES', 90),

        /*
         * Caps on the form, enforced by the request rules.
         *
         * A standup is a thing people answer standing up. Fifteen questions is
         * a form, and a form is what everybody stops filling in by Thursday.
         */
        'max_questions' => 10,
        'max_question_length' => 200,
        'max_answer_length' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Bite
    |--------------------------------------------------------------------------
    |
    | The workspace assistant. Bite is addressed the way a colleague is — by
    | name in a channel, or in a direct message — and answers using the same
    | data the person asking could have found by navigating. Everything it can
    | reach is bounded by that: see App\Ai\BiteContext.
    |
    */

    'bite' => [
        /*
         * The master switch.
         *
         * Off by default, because Bite talks to a third party. An installation
         * that has not deliberately turned it on must not start posting its
         * members' messages to an AI provider because a dependency happened to
         * be installed.
         */
        'enabled' => (bool) env('SNACK_BITE_ENABLED', false),

        /*
         * The handle people address it by, in addition to the account's real
         * username.
         *
         * `users.username` is unique across the installation, so only the
         * first workspace's account can actually hold "bite" — the rest are
         * "bite-{workspace_id}". Summoning does not go through that column
         * (see App\Services\BiteSummons), so every workspace answers to the
         * same word regardless of which one got the short name.
         */
        'handle' => 'bite',

        /*
         * How many turns of the surrounding conversation Bite is given.
         *
         * It reads the thread or DM it was addressed in so that "what about
         * the other one?" means something. Deep enough to carry a real
         * exchange, shallow enough that a year-old DM does not become a
         * five-figure token bill on every "thanks".
         */
        'context_messages' => 20,

        /*
         * The ceiling on one answer's tool-calling loop.
         *
         * Each step is a provider round trip, so this is the difference
         * between an answer that takes four seconds and one that takes a
         * minute. Eight is enough to search, narrow, read a file, and reply.
         */
        'max_steps' => 8,

        /*
         * How long the "Bite is replying…" indicator stays up unattended.
         *
         * The indicator is switched off explicitly when the answer is posted
         * (see App\Events\BiteReplying), so this only ever matters when that
         * never happens: a worker killed mid-answer, a deploy, a provider call
         * that hangs past the job's own timeout. Comfortably longer than a
         * normal answer, comfortably shorter than the patience of somebody
         * watching an assistant that is no longer running.
         */
        'replying_ttl_seconds' => 60,

        /*
         * The admin-curated facts Bite is given on every question.
         *
         * Every enabled entry goes into the system prompt of every answer — see
         * the `bite_memories` migration for why that is deliberate — so this is
         * a bill the workspace pays per message, not per lookup. The caps are
         * what keep a knowledge base from quietly becoming the most expensive
         * thing in the application: they are enforced when an entry is saved,
         * so the admin is told at the point of typing rather than by a provider
         * error days later, and again when the prompt is assembled.
         */
        'knowledge' => [
            /** The longest one fact may be. */
            'max_entry_characters' => 2_000,

            /**
             * And the most all of them may come to together. Roughly six
             * thousand tokens — large enough for a page of house rules, small
             * enough to leave the context window to the conversation.
             */
            'max_characters' => 24_000,
        ],

        /*
         * The ceiling on the house instructions an admin can append to Bite's
         * system prompt. Long enough for a paragraph about tone and what to
         * defer on; short enough that it cannot become the prompt.
         */
        'max_instruction_characters' => 2_000,

        'files' => [
            /*
             * The most text Bite will inline from one file.
             *
             * Files are read into the prompt whole; a 40MB CSV would blow the
             * context window and the budget in a single tool call. Past this
             * the content is truncated and said to be truncated, which is
             * recoverable — silently sending half a file is not.
             */
            'max_bytes' => (int) env('SNACK_BITE_FILE_MAX_BYTES', 200_000),

            /*
             * How long a document URL handed to the provider stays valid.
             *
             * PDFs cannot be inlined — the provider fetches them itself — so
             * reading one means minting a temporary URL to a private object.
             * Long enough for that fetch, short enough that the URL is worth
             * nothing by the time it reaches a log.
             */
            'url_ttl_minutes' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP connectors
    |--------------------------------------------------------------------------
    |
    | The Model Context Protocol server at `/mcp`, which lets an AI client —
    | Claude, Cursor, anything that speaks MCP — search and post in a workspace
    | as the person who connected it. See App\Mcp\Servers\SnackServer.
    |
    */

    'mcp' => [
        /*
         * Tool calls per minute, per person. An assistant answering one
         * question makes a quick burst of calls; this is set to absorb that and
         * stop a client stuck in a retry loop.
         */
        'rate_limit_per_minute' => (int) env('SNACK_MCP_RATE_LIMIT', 120),

        /*
         * How many connector tokens one person may hold in one workspace. Each
         * is a live credential; a list of forty is a list nobody audits.
         */
        'max_tokens_per_member' => 10,

        /*
         * The largest file `read_file` will hand back. It goes to the client
         * whole, Base64 in a JSON response, so a 200 MB video is a request
         * nobody wants — and no model can read one that size anyway.
         */
        'max_file_bytes' => (int) env('SNACK_MCP_MAX_FILE_BYTES', 10 * 1024 * 1024),

        /*
         * How long a download link an assistant hands out keeps working. The
         * link still needs a Snack login and a policy check to open — see
         * SnackTool::downloadUrl() — so this bounds replay, not access.
         */
        'download_link_ttl_hours' => (int) env('SNACK_MCP_DOWNLOAD_LINK_TTL_HOURS', 24),
    ],

    'mobile' => [
        /*
         * Where the mobile app opens its WebSocket — the public address of
         * Reverb, which is the one the browser bundle is built with rather
         * than the internal one the server publishes to. Handed to the app by
         * `GET /api/v1/config` so a build does not hard-code a host.
         */
        'reverb' => [
            'key' => env('REVERB_APP_KEY'),
            'host' => env('VITE_REVERB_HOST', env('REVERB_HOST')),
            'port' => (int) env('VITE_REVERB_PORT', env('REVERB_PORT', 443)),
            'scheme' => env('VITE_REVERB_SCHEME', env('REVERB_SCHEME', 'https')),
        ],

        /*
         * The sign-in handed to App Store and Play Store reviewers, written by
         * `php artisan db:seed --class=AppReviewSeeder --force`. The account is
         * a member of its own demo workspace — `workspace` is that workspace's
         * slug — filled with made-up colleagues and conversation, so a reviewer
         * can try everything without being anywhere near a real team.
         */
        'app_review' => [
            'name' => env('SNACK_APP_REVIEW_NAME', 'App Review'),
            'email' => env('SNACK_APP_REVIEW_EMAIL', 'appreview@example.com'),
            'username' => env('SNACK_APP_REVIEW_USERNAME', 'appreview'),
            'password' => env('SNACK_APP_REVIEW_PASSWORD'),
            'workspace' => env('SNACK_APP_REVIEW_WORKSPACE', 'snack-demo'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | The agent dispatcher that hands tickets to people's local coding agents.
    | Snack sends it signed events — task eligibility changes, messages in
    | project agent channels, agent settings changes — through a transactional
    | outbox. See App\Services\Outbound\OutboundEvents.
    |
    | One fixed endpoint from the environment rather than anything configurable
    | in the app: an admin-editable URL would be a way to make the server POST
    | private channel contents anywhere.
    |
    | Off unless both are set. Nothing is recorded while it is off, so turning
    | it on later does not replay history.
    |
    */

    'agents' => [
        // Must be https unless `allow_insecure` says otherwise; anything else
        // leaves the feature off — see OutboundEvents::enabled().
        'dispatcher_url' => env('SNACK_AGENT_DISPATCHER_URL'),
        'dispatcher_secret' => env('SNACK_AGENT_DISPATCHER_SECRET'),

        // Plain http, for a dispatcher on this machine during development. An
        // explicit opt-in rather than inferred from APP_ENV, which a
        // misconfigured server can claim to be anything.
        'allow_insecure' => (bool) env('SNACK_AGENT_ALLOW_INSECURE', false),

        /*
         * How long a change that reports to the dispatcher waits for another
         * one in the same workspace before giving up — see
         * OutboundEvents::transaction(). A Postgres interval.
         */
        'lock_timeout' => '10s',

        /*
         * Deliveries run on their own queue so that a dispatcher outage — every
         * event retrying, each waiting out its timeout — cannot hold the workers
         * that broadcast messages and send notifications. A worker has to be
         * listening on it: `queue:work --queue=default,outbound`.
         */
        'queue' => env('SNACK_AGENT_QUEUE', 'outbound'),
    ],

];
