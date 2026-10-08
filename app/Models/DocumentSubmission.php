<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's answer to a {@see DocumentRequest}: the file they uploaded and
 * what HR made of it.
 *
 * @property int $id
 * @property int $document_request_id
 * @property int $employee_id
 * @property DocumentStatus $status
 * @property string|null $file_path
 * @property string|null $file_name
 * @property Carbon|null $expires_on
 * @property Carbon|null $submitted_at
 * @property int|null $reviewer_id
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DocumentRequest $documentRequest
 * @property-read Employee $employee
 * @property-read User|null $reviewer
 */
#[Fillable([
    'document_request_id', 'employee_id', 'status', 'file_path', 'file_name', 'expires_on',
    'submitted_at', 'reviewer_id', 'reviewed_at', 'review_note',
])]
class DocumentSubmission extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'expires_on' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentRequest, $this>
     */
    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', DocumentStatus::Submitted);
    }
}
