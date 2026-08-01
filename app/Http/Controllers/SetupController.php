<?php

namespace App\Http\Controllers;

use App\Actions\Setup\CompleteInstanceSetup;
use App\Http\Middleware\EnsureInstanceNeedsSetup;
use App\Http\Requests\Setup\CompleteSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First-run setup: the screen a fresh installation opens on.
 *
 * Reachable only while no account exists — see
 * {@see EnsureInstanceNeedsSetup}, which is applied to
 * both routes and 404s the moment the first user is written.
 */
class SetupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('setup/index', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'maxInvitations' => CompleteSetupRequest::maxInvitations(),
            'defaultChannels' => array_map(
                fn (array $channel): string => $channel['name'],
                config('snack.workspaces.default_channels', []),
            ),
        ]);
    }

    public function store(CompleteSetupRequest $request, CompleteInstanceSetup $completeSetup): RedirectResponse
    {
        $result = $completeSetup->handle(
            admin: $request->adminAttributes(),
            workspace: $request->workspaceAttributes(),
            invitations: $request->invitationEmails(),
        );

        // Signing the owner in here is what makes setup a single step. They
        // just proved control of the installation by initializing it, and
        // bouncing them to a login form to re-enter credentials they set
        // moments ago would be ceremony, not security.
        Auth::login($result['user']);
        $request->session()->regenerate();

        return to_route('workspaces.show', ['workspace' => $result['workspace']->slug])
            ->with('success', $this->confirmation($result['workspace']->name, $result['failedInvitations']));
    }

    /**
     * @param  list<string>  $failedInvitations
     */
    private function confirmation(string $workspaceName, array $failedInvitations): string
    {
        if ($failedInvitations === []) {
            return "{$workspaceName} is ready. Small chats, big work.";
        }

        // Named rather than swallowed: these people are not getting an email,
        // and the admin is the only one who can find that out.
        return sprintf(
            '%s is ready, but invitations could not be sent to %s. Check your mail configuration and re-send them from the invitations page.',
            $workspaceName,
            implode(', ', $failedInvitations),
        );
    }
}
