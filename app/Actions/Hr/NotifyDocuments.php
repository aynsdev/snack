<?php

namespace App\Actions\Hr;

use App\Enums\DocumentStatus;
use App\Models\DocumentRequest;
use App\Models\DocumentSubmission;
use App\Models\Employee;
use App\Services\Hr\HrReviewers;
use App\Services\HrBot;

/**
 * Tells people about document requests, as direct messages from the HR bot —
 * see {@see NotifyLeave} for why. Delivery failures are reported, never thrown.
 */
class NotifyDocuments
{
    public function __construct(private readonly HrBot $bot) {}

    /**
     * HR asked for something: tell each person it was asked of.
     *
     * @param  iterable<int, Employee>  $employees
     */
    public function requested(DocumentRequest $documentRequest, iterable $employees): void
    {
        $documentRequest->loadMissing('workspace');

        $lines = ["HR asked you for **{$documentRequest->title}**".($documentRequest->due_on ? ' by '.$documentRequest->due_on->format('M j, Y') : '').'.'];

        if ($documentRequest->description) {
            $lines[] = '> '.str_replace("\n", ' ', $documentRequest->description);
        }

        $lines[] = 'Upload it: '.$this->link($documentRequest);

        foreach ($employees as $employee) {
            rescue(fn () => $this->bot->directMessage($documentRequest->workspace, $employee->user, implode("\n", $lines)));
        }
    }

    /**
     * Somebody uploaded: tell HR it is waiting on them.
     */
    public function submitted(DocumentSubmission $submission): void
    {
        $submission->loadMissing(['documentRequest.workspace', 'employee.user']);

        $request = $submission->documentRequest;
        $uploader = $submission->employee->user;
        $body = "**{$uploader->name}** uploaded **{$request->title}**.\nReview it: ".$this->link($request);

        foreach (HrReviewers::for($request->workspace_id, $uploader) as $reviewer) {
            rescue(fn () => $this->bot->directMessage($request->workspace, $reviewer, $body));
        }
    }

    /**
     * HR decided: tell the person who uploaded.
     */
    public function decided(DocumentSubmission $submission): void
    {
        $submission->loadMissing(['documentRequest.workspace', 'employee.user', 'reviewer']);

        $request = $submission->documentRequest;
        $verdict = $submission->status === DocumentStatus::Approved
            ? 'was **approved** ✅'
            : 'was **rejected** — please upload it again';

        $lines = ["Your **{$request->title}** {$verdict}."];

        if ($submission->review_note) {
            $lines[] = '> '.str_replace("\n", ' ', $submission->review_note);
        }

        $lines[] = $this->link($request);

        rescue(fn () => $this->bot->directMessage($request->workspace, $submission->employee->user, implode("\n", $lines)));
    }

    private function link(DocumentRequest $documentRequest): string
    {
        return route('hr.documents.index', ['workspace' => $documentRequest->workspace->slug]);
    }
}
