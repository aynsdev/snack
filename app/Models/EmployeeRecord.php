<?php

namespace App\Models;

use App\Enums\EmployeeRecordType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An entry on somebody's HR file: a note, a warning, a review, a document.
 *
 * @property int $id
 * @property int $employee_id
 * @property int|null $recorded_by
 * @property EmployeeRecordType $type
 * @property string $title
 * @property string|null $body
 * @property Carbon $recorded_on
 * @property string|null $file_path
 * @property string|null $file_name
 * @property-read Employee $employee
 * @property-read User|null $recorder
 */
#[Fillable(['employee_id', 'recorded_by', 'type', 'title', 'body', 'recorded_on', 'file_path', 'file_name'])]
class EmployeeRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => EmployeeRecordType::class,
            'recorded_on' => 'date',
        ];
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
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
