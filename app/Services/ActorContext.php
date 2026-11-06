<?php

namespace App\Services;

use App\Enums\ConnectorKind;
use App\Http\Middleware\EnsureMobileToken;
use App\Mcp\ConnectorAccess;
use App\Models\OAuthToken;
use App\Models\User;
use App\Support\Actor;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Laravel\Passport\AccessToken;

/**
 * Who is making the current change, and through which door.
 *
 * Read from the authenticated principal rather than passed down from every
 * controller: the same {@see App\Actions\Messages\SendMessage} serves the
 * composer, the MCP server, and webhooks, and threading "how did this arrive"
 * through each of them would be a parameter every future caller could forget.
 *
 * - A Passport bearer token the mobile app signed in for — the `mobile` scope,
 *   issued by Snack's personal access client, as {@see EnsureMobileToken}
 *   decides — is the person themselves: `mobile`.
 * - Any other Passport bearer token is an MCP connector; when the token row's
 *   kind is `agent` it is that person's agent rather than the person.
 * - Any other authenticated user arrived through a session: `web`.
 * - Nobody authenticated — a queued job, a console command — is `system`.
 *
 * Webhook deliveries authenticate with nothing, so the delivery path declares
 * itself with {@see using()}. Tests use the same seam.
 *
 * Scoped: one instance per request or job, so neither an override nor the
 * remembered token row can leak into the next one.
 */
class ActorContext
{
    private ?Actor $override = null;

    /** @var array<string, OAuthToken|null> */
    private array $connectors = [];

    /** @var array<string, bool> */
    private array $mobileTokens = [];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ConnectorAccess $access,
        private readonly EnsureMobileToken $mobile,
    ) {}

    public function current(): Actor
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $guard = $this->auth->guard();

        /*
         * `hasUser`, not `user`: asking the guard to authenticate here would
         * make a console command or an unauthenticated route try to read a
         * session it does not have. Every route that changes anything has
         * already been through `auth`, which resolved the user.
         */
        if (! $guard->hasUser()) {
            return Actor::system();
        }

        $user = $guard->user();

        if (! $user instanceof User) {
            return Actor::system();
        }

        $token = $user->token();

        if (! $token instanceof AccessToken) {
            return Actor::web($user);
        }

        if ($this->isMobile($user, $token)) {
            return Actor::mobile($user);
        }

        $connector = $this->connector($user, $token);

        if ($connector?->connector_kind === ConnectorKind::Agent) {
            return Actor::agent($user, $connector);
        }

        return Actor::mcp($user, (string) $token->oauth_access_token_id);
    }

    /**
     * Run a callback as somebody other than the authenticated principal.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function using(Actor $actor, Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = $actor;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    /**
     * Remembered per token: deciding reads the issuing client, and one request
     * can ask who is acting many times over.
     *
     * @param  AccessToken<mixed>  $token
     */
    private function isMobile(User $user, AccessToken $token): bool
    {
        $id = (string) $token->oauth_access_token_id;

        return $this->mobileTokens[$id] ??= $this->mobile->admits($user);
    }

    /**
     * @param  AccessToken<mixed>  $token
     */
    private function connector(User $user, AccessToken $token): ?OAuthToken
    {
        $id = (string) $token->oauth_access_token_id;

        if (! array_key_exists($id, $this->connectors)) {
            $this->connectors[$id] = $this->access->token($user);
        }

        return $this->connectors[$id];
    }
}
