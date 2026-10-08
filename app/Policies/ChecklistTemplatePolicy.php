<?php

namespace App\Policies;

use App\Models\ChecklistTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * HR writes the onboarding and offboarding templates.
 */
class ChecklistTemplatePolicy
{
    use ManagesPeople;

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function update(User $user, ChecklistTemplate $template): Response
    {
        return $this->managesPeople($user, $template->workspace);
    }

    public function delete(User $user, ChecklistTemplate $template): Response
    {
        return $this->managesPeople($user, $template->workspace);
    }
}
