<?php

use App\Models\Huddle;
use App\Models\User;
use App\Services\LiveKit\TokenIssuer;

/**
 * The join token is the entire authorization boundary for huddle media: LiveKit
 * never talks to Laravel, it reads these claims and honours them. A token that
 * is subtly wrong does not fail loudly — it produces a call nobody can join, or
 * worse, one scoped to more than it should be.
 *
 * These assert the wire format rather than the implementation, because the wire
 * format is a contract with a Go server that unmarshals exactly these names.
 *
 * A Feature test rather than a Unit one: {@see TokenIssuer} reads its
 * credentials from config, and unit tests do not boot the framework.
 */

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}
 */
function decodeToken(string $token): array
{
    $segments = explode('.', $token);

    expect($segments)->toHaveCount(3);

    $decode = fn (string $segment): array => json_decode(
        base64_decode(strtr($segment, '-_', '+/')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );

    return [$decode($segments[0]), $decode($segments[1]), $segments[2]];
}

it('signs a token LiveKit can verify with the shared secret', function () {
    $issuer = app(TokenIssuer::class);
    $huddle = Huddle::factory()->make(['public_id' => 'test-room-id']);

    $token = $issuer->issue($huddle, User::factory()->create());

    [$header, , $signature] = decodeToken($token);

    expect($header['alg'])->toBe('HS256')
        ->and($header['typ'])->toBe('JWT');

    // Re-sign the payload the way LiveKit would and compare. This is what
    // catches a base64url slip: ordinary base64 emits `+`, `/` and `=`, none of
    // which survive a JWT intact.
    $payload = implode('.', array_slice(explode('.', $token), 0, 2));

    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $payload, config('services.livekit.secret'), binary: true),
    ), '+/', '-_'), '=');

    expect($signature)->toBe($expected)
        ->and($signature)->not->toContain('+')
        ->and($signature)->not->toContain('/')
        ->and($signature)->not->toContain('=');
});

it('scopes the grant to one room and one identity', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);
    $huddle = Huddle::factory()->make(['public_id' => '01J0000000000000000000000A']);

    [, $claims] = decodeToken(app(TokenIssuer::class)->issue($huddle, $user));

    expect($claims['iss'])->toBe('snack-testing-key')
        ->and($claims['sub'])->toBe('user.'.$user->id)
        ->and($claims['name'])->toBe('Ada Lovelace')
        ->and($claims['video']['room'])->toBe('01J0000000000000000000000A')
        ->and($claims['video']['roomJoin'])->toBeTrue();
});

/**
 * A participant needs none of these, and each would let anybody in a huddle
 * enumerate or evict everybody else's.
 */
it('withholds every administrative grant', function () {
    $huddle = Huddle::factory()->make();

    [, $claims] = decodeToken(
        app(TokenIssuer::class)->issue($huddle, User::factory()->create()),
    );

    expect($claims['video'])
        ->not->toHaveKey('roomAdmin')
        ->not->toHaveKey('roomCreate')
        ->not->toHaveKey('roomList')
        ->not->toHaveKey('roomRecord')
        ->not->toHaveKey('ingressAdmin');
});

it('grants speaking, listening and screen sharing', function () {
    $huddle = Huddle::factory()->make();

    [, $claims] = decodeToken(
        app(TokenIssuer::class)->issue($huddle, User::factory()->create()),
    );

    expect($claims['video']['canPublish'])->toBeTrue()
        ->and($claims['video']['canSubscribe'])->toBeTrue()
        ->and($claims['video']['canPublishData'])->toBeTrue()
        ->and($claims['video']['canUpdateOwnMetadata'])->toBeTrue();
});

/**
 * `nbf` is backdated because the media server is a separate process with its own
 * clock. A token minted "now" and validated against a clock a few seconds behind
 * is not yet valid, and LiveKit refuses it with an error that says nothing about
 * clocks.
 */
it('backdates nbf and expires on the configured window', function () {
    config()->set('snack.huddles.token_ttl_minutes', 30);

    $huddle = Huddle::factory()->make();

    [, $claims] = decodeToken(
        app(TokenIssuer::class)->issue($huddle, User::factory()->create()),
    );

    expect($claims['nbf'])->toBeLessThan(now()->getTimestamp())
        ->and($claims['exp'])->toBeGreaterThan(now()->addMinutes(29)->getTimestamp())
        ->and($claims['exp'])->toBeLessThanOrEqual(now()->addMinutes(30)->getTimestamp());
});

/**
 * Metadata is carried through the media server untouched and delivered to every
 * other participant, without passing through any Snack authorization on the way
 * out — so it must contain only what a huddle tile draws.
 */
it('carries only what a tile renders in the participant metadata', function () {
    $user = User::factory()->create(['email' => 'ada@example.test']);
    $huddle = Huddle::factory()->make();

    [, $claims] = decodeToken(app(TokenIssuer::class)->issue($huddle, $user));

    $metadata = json_decode($claims['metadata'], associative: true);

    expect(array_keys($metadata))
        ->toEqualCanonicalizing(['user_id', 'username', 'avatar_url'])
        ->and($metadata['user_id'])->toBe($user->id)
        ->and($claims['metadata'])->not->toContain('ada@example.test');
});

it('reads a user id back out of an identity it minted', function () {
    $user = User::factory()->create();

    expect(TokenIssuer::userIdFromIdentity(TokenIssuer::identityFor($user)))
        ->toBe($user->id);
});

/**
 * The identity arrives from the media server, which means it arrives from
 * outside this application.
 */
it('refuses to read an identity it did not mint', function (string $identity) {
    expect(TokenIssuer::userIdFromIdentity($identity))->toBeNull();
})->with(['bot.7', 'user.', 'user.abc', 'user.7x', '7', '']);

it('reports itself unconfigured when the secret is missing', function () {
    config()->set('services.livekit.secret', null);

    expect(app(TokenIssuer::class)->isConfigured())->toBeFalse();
});

it('refuses to mint a token without credentials', function () {
    config()->set('services.livekit.secret', '');

    app(TokenIssuer::class)->issue(
        Huddle::factory()->make(),
        User::factory()->create(),
    );
})->throws(LogicException::class);
