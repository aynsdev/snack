<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Models\DocumentSubmission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Your own documents are yours to upload and read; HR reads and decides
 * everybody's. Anybody else is told the document does not exist.
 */
class DocumentSubmissionPolicy
{
    use ManagesPeople;

    public function view(User $user, DocumentSubmission $submission): Response
    {
        if ($submission->employee->user_id === $user->id) {
            return Response::allow();
        }

        return $this->managesPeople($user, $submission->documentRequest->workspace)->allowed()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function upload(User $user, DocumentSubmission $submission): Response
    {
        if ($submission->employee->user_id !== $user->id) {
            return Response::denyAsNotFound();
        }

        return $submission->status->acceptsUpload()
            ? Response::allow()
            : Response::deny('This document has already been uploaded.');
    }

    public function review(User $user, DocumentSubmission $submission): Response
    {
        $access = $this->managesPeople($user, $submission->documentRequest->workspace);

        if ($access->denied()) {
            return $access;
        }

        return $submission->status === DocumentStatus::Submitted
            ? Response::allow()
            : Response::deny('Only an uploaded document can be reviewed.');
    }
}
