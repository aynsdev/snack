<?php

namespace App\Actions\Hr;

use App\Actions\Audit\RecordAuditLog;
use App\Enums\AuditAction;
use App\Enums\EquipmentStatus;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handing company equipment to somebody, and taking it back.
 */
class HandOverEquipment
{
    public function __construct(
        private readonly RecordAuditLog $audit,
        private readonly NotifyEquipment $notify,
    ) {}

    public function assign(
        Equipment $equipment,
        Employee $employee,
        User $actor,
        ?string $condition = null,
        ?CarbonInterface $on = null,
    ): EquipmentAssignment {
        if ($equipment->status !== EquipmentStatus::Available) {
            throw ValidationException::withMessages([
                'equipment_id' => "{$equipment->name} is {$equipment->status->label()}, not available.",
            ]);
        }

        try {
            $assignment = DB::transaction(function () use ($equipment, $employee, $actor, $condition, $on): EquipmentAssignment {
                $assignment = $equipment->assignments()->create([
                    'employee_id' => $employee->id,
                    'assigned_on' => ($on ?? today())->toDateString(),
                    'assigned_by' => $actor->id,
                    'condition_out' => $condition,
                ]);

                $equipment->update(['status' => EquipmentStatus::Assigned]);

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people handing out the same item at once.
            throw ValidationException::withMessages(['equipment_id' => "{$equipment->name} has just been handed out."]);
        }

        $this->audit->handle($equipment->workspace, AuditAction::EquipmentAssigned, $actor, $equipment, newValues: [
            'equipment' => $equipment->name,
            'employee' => $employee->user->name,
        ]);

        $this->notify->assigned($assignment);

        return $assignment;
    }

    /**
     * Records the item coming back, and what state it is in now: ready to
     * hand out again, off for repair, or retired.
     */
    public function takeBack(
        EquipmentAssignment $assignment,
        User $actor,
        EquipmentStatus $status = EquipmentStatus::Available,
        ?string $condition = null,
        ?CarbonInterface $on = null,
    ): EquipmentAssignment {
        $equipment = $assignment->equipment;

        DB::transaction(function () use ($assignment, $equipment, $actor, $status, $condition, $on): void {
            $assignment->update([
                'returned_on' => ($on ?? today())->toDateString(),
                'returned_by' => $actor->id,
                'condition_in' => $condition,
            ]);

            $equipment->update(['status' => $status]);
        });

        $this->audit->handle($equipment->workspace, AuditAction::EquipmentReturned, $actor, $equipment, newValues: [
            'equipment' => $equipment->name,
            'employee' => $assignment->employee->user->name,
            'status' => $status->value,
        ]);

        return $assignment;
    }
}
