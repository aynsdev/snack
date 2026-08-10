<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | DigitalOcean Spaces
        |----------------------------------------------------------------------
        |
        | Two disks over one bucket, because Snack stores two kinds of thing and
        | they have opposite access rules.
        |
        | "spaces" is private, and it is where message attachments and asset
        | library files live. Nothing here is ever served by URL: every download
        | goes through App\Http\Controllers\Files\DownloadController, which
        | re-authorizes the viewer against the message, card, or asset the bytes
        | belong to on every single hit. The bucket must therefore be private —
        | if an object in it is reachable without the application, the entire
        | permission model is decoration.
        |
        | "spaces_public" is public, and it is only ever avatars and workspace
        | logos. Those render beside every message their owner has ever written,
        | for everyone who can read it, so serving them through a signed
        | per-viewer route would mean a database round trip per face on screen.
        | See the "avatars" block in config/snack.php.
        |
        | Spaces speaks S3, so the driver is the same one AWS uses; what differs
        | is the endpoint, and — for the public disk — that reads are served from
        | the CDN hostname rather than the origin. Set SPACES_PUBLIC_URL to the
        | CDN endpoint (…​.cdn.digitaloceanspaces.com) and images are cached at
        | the edge instead of billed as origin bandwidth on every page load.
        |
        */

        'spaces' => [
            'driver' => 's3',
            'key' => env('SPACES_KEY'),
            'secret' => env('SPACES_SECRET'),
            'region' => env('SPACES_REGION', 'sgp1'),
            'bucket' => env('SPACES_BUCKET'),
            'endpoint' => env('SPACES_ENDPOINT'),
            /*
             * Spaces uses virtual-host style addressing
             * (bucket.region.digitaloceanspaces.com), which is what the AWS SDK
             * does by default — so this stays false. Setting it true produces
             * URLs Spaces answers with 403.
             */
            'use_path_style_endpoint' => false,
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        'spaces_public' => [
            'driver' => 's3',
            'key' => env('SPACES_KEY'),
            'secret' => env('SPACES_SECRET'),
            'region' => env('SPACES_REGION', 'sgp1'),
            /*
             * Its own bucket when one is given, and the same bucket otherwise.
             *
             * One bucket works — Spaces honours per-object ACLs, so a public
             * avatar can sit in a private bucket — but two is the safer default
             * to reach for, because it lets the private bucket be locked down at
             * the bucket level rather than relying on every write remembering to
             * ask for the right visibility.
             */
            'bucket' => env('SPACES_PUBLIC_BUCKET', env('SPACES_BUCKET')),
            'endpoint' => env('SPACES_ENDPOINT'),
            // Where the client is told to fetch from: the CDN edge, not the
            // origin the application writes to.
            'url' => env('SPACES_PUBLIC_URL'),
            'use_path_style_endpoint' => false,
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
