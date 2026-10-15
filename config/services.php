<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | LiveKit
    |--------------------------------------------------------------------------
    |
    | The media server huddles run on. LiveKit is open source and self-hosted
    | here — see docker-compose.livekit.yml — so no audio or video ever leaves
    | the deployment's own infrastructure. LiveKit Cloud speaks the same
    | protocol and the same three values point at it instead.
    |
    | "url" is the only one the browser is given, and it is handed out with each
    | join token rather than compiled into the bundle: the key and secret never
    | leave the server, which is what makes App\Services\LiveKit\TokenIssuer the
    | single place a huddle can be authorized.
    |
    | Unset credentials are not an error. Huddles simply do not appear — see
    | snack.huddles.enabled — because a deployment that has not stood up a media
    | server should offer a button that does nothing rather than one that fails.
    |
    */

    'livekit' => [
        'key' => env('LIVEKIT_API_KEY'),
        'secret' => env('LIVEKIT_API_SECRET'),

        // The signalling URL the browser dials: ws:// locally, wss:// anywhere
        // a page is served over HTTPS, since a secure page cannot open an
        // insecure socket.
        'url' => env('LIVEKIT_URL', 'ws://localhost:7880'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Magnific
    |--------------------------------------------------------------------------
    |
    | The image and video generation API behind Bite's `generate_image` and
    | `generate_video` tools. Billed per generation, so the key is the switch:
    | without one neither tool is handed to the model, whatever a workspace has
    | ticked on its Bite screen. See App\Services\Magnific.
    |
    */

    'magnific' => [
        'key' => env('MAGNIFIC_API_KEY'),
        'url' => env('MAGNIFIC_API_URL', 'https://api.magnific.com'),

        // How long a generation may run before Bite gives up and says so. See
        // App\Jobs\GenerateBiteMedia, which checks on it every few seconds.
        'image_timeout_minutes' => (int) env('MAGNIFIC_IMAGE_TIMEOUT_MINUTES', 5),
        'video_timeout_minutes' => (int) env('MAGNIFIC_VIDEO_TIMEOUT_MINUTES', 20),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
