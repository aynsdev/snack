<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Who may configure the assistant.
 *
 * Workspace administration, for the reason standups are: this decides what the
 * application does on behalf of every member — where an assistant may speak,
 * who may summon it, and what facts it repeats when asked. None of that is a
 * preference somebody sets for themselves.
 *
 * # Reading is the same right as writing
 *
 * Unusual, and deliberate, matching {@see IncomingWebhookPolicy}. The screen
 * shows the house instructions and the knowledge base, which is the text a
 * workspace has decided its assistant should say — including, in practice,
 * things like which of two vendors is being dropped. That is not a roster; it
 * is a document, and being able to read it is worth the same rank as being able
 * to change it.
 *
 * # The knowledge base answers to this policy too
 *
 * Entries are not separately authorized. They are part of one screen and one
 * decision — "what does our assistant know and do" — and giving them their own
 * rank would mean a role that could rewrite what Bite tells the workspace but
 * not turn it off, which is the wrong way round.
 */
class BiteSettingPolicy
{
    public function viewAny(User $user, Workspace $workspace): Response
    {
        return $this->administers($user, $workspace);
    }

    public function update(User $user, Workspace $workspace): Response
    {
        return $this->administers($user, $workspace);
    }

    /**
     * The one rule, written once.
     *
     * A denial rather than a 404: unlike a private channel, the existence of the
     * settings screen is not a secret — every member can see the assistant
     * answering — so there is nothing to hide by pretending the page is missing.
     */
    private function administers(User $user, Workspace $workspace): Response
    {
        return $user->roleIn($workspace)?->canAdministerWorkspace() === true
            ? Response::allow()
            : Response::deny('Only workspace admins can configure Bite.');
    }
}
