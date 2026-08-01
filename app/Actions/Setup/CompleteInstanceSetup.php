<?php

namespace App\Actions\Setup;

use App\Actions\Workspaces\CreateWorkspace;
use App\Actions\Workspaces\InviteWorkspaceMember;
use App\Enums\WorkspaceRole;
use App\Http\Middleware\EnsureInstanceNeedsSetup;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Initializes a fresh installation: the first account, the first workspace, and
 * the invitations that populate it.
 *
 * This is the only path that creates a user without an invitation, so it must
 * never run on an installation that already has one. That guarantee lives in
 * {@see EnsureInstanceNeedsSetup} and is re-asserted here
 * inside the transaction, where a concurrent second submission would otherwise
 * slip past the middleware's check.
 */
class CompleteInstanceSetup
{
    public function __construct(
        private readonly CreateWorkspace $createWorkspace,
        private readonly InviteWorkspaceMember $inviteMember,
        private readonly SlugGenerator $slugs,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $admin
     * @param  array{name: string, description: string|null}  $workspace
     * @param  list<string>  $invitations
     * @return array{user: User, workspace: Workspace, failedInvitations: list<string>}
     */
    public function handle(array $admin, array $workspace, array $invitations = []): array
    {
        /** @var array{user: User, workspace: Workspace} $created */
        $created = DB::transaction(function () use ($admin, $workspace): array {
            // Re-check under the transaction. Two people opening the setup form
            // on the same fresh instance would both clear the middleware; only
            // the first may proceed, and the loser gets a validation error
            // rather than a second stray owner.
            abort_if(User::query()->lockForUpdate()->exists(), 404);

            $user = User::create([
                'name' => $admin['name'],
                'email' => $admin['email'],
                'username' => $this->slugs->forUsername($admin['name']),
                'password' => $admin['password'],
                'timezone' => config('app.timezone', 'UTC'),
            ]);

            // Nobody exists yet who could action a verification email, and the
            // person running setup demonstrably controls the installation.
            // Leaving them unverified would lock them out of every route behind
            // the `verified` middleware — which is all of them.
            $user->markEmailAsVerified();

            return [
                'user' => $user,
                'workspace' => $this->createWorkspace->handle($user, [
                    'name' => $workspace['name'],
                    'description' => $workspace['description'],
                ]),
            ];
        });

        return [
            ...$created,
            'failedInvitations' => $this->invite($created['workspace'], $created['user'], $invitations),
        ];
    }

    /**
     * Issue the opening invitations, tolerating a broken mail or queue config.
     *
     * Deliberately outside the transaction and deliberately non-fatal. Setup is
     * a one-shot operation — once the owner exists it can never be re-run — so
     * an unreachable SMTP host must not be allowed to roll back the account and
     * strand the installation with no way in. Addresses that failed are handed
     * back so the UI can name them; the admin can re-send from the invitations
     * page, which is the same screen they would have used anyway.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    private function invite(Workspace $workspace, User $inviter, array $emails): array
    {
        $failed = [];

        foreach ($emails as $email) {
            try {
                $this->inviteMember->handle(
                    workspace: $workspace,
                    inviter: $inviter,
                    email: $email,
                    role: WorkspaceRole::Member,
                );
            } catch (Throwable $exception) {
                Log::warning('Setup invitation failed.', [
                    'email' => $email,
                    'workspace_id' => $workspace->id,
                    'exception' => $exception->getMessage(),
                ]);

                $failed[] = $email;
            }
        }

        return $failed;
    }
}
