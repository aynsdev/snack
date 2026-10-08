<?php

namespace App\Policies;

use App\Models\DocumentRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * HR asks for paperwork; nobody else does.
 */
class DocumentRequestPolicy
{
    use ManagesPeople;

    public function create(User $user, Workspace $workspace): Response
    {
        return $this->managesPeople($user, $workspace);
    }

    public function delete(User $user, DocumentRequest $documentRequest): Response
    {
        return $this->managesPeople($user, $documentRequest->workspace);
    }
}
