<?php

namespace App\Actions\Hr;

use App\Models\EquipmentAssignment;
use App\Services\Hr\HrReviewers;
use App\Services\HrBot;

/**
 * Tells people about equipment changing hands, as direct messages from the HR
 * bot — see {@see NotifyLeave} for why. Delivery failures are reported, never
 * thrown.
 */
class NotifyEquipment
{
    public function __construct(private readonly HrBot $bot) {}

    public function assigned(EquipmentAssignment $assignment): void
    {
        $assignment->loadMissing(['equipment.workspace', 'employee.user']);

        $equipment = $assignment->equipment;
        $label = $equipment->name.($equipment->tracking_code ? " ({$equipment->tracking_code})" : '');

        rescue(fn () => $this->bot->directMessage(
            $equipment->workspace,
            $assignment->employee->user,
            "**{$label}** has been handed to you.\n".route('hr.my-equipment', ['workspace' => $equipment->workspace->slug]),
        ));
    }

    public function returnRequested(EquipmentAssignment $assignment): void
    {
        $assignment->loadMissing(['equipment.workspace', 'employee.user']);

        $equipment = $assignment->equipment;
        $holder = $assignment->employee->user;
        $body = "**{$holder->name}** wants to return **{$equipment->name}**.\n"
            .route('hr.equipment.index', ['workspace' => $equipment->workspace->slug]);

        foreach (HrReviewers::for($equipment->workspace_id, $holder) as $reviewer) {
            rescue(fn () => $this->bot->directMessage($equipment->workspace, $reviewer, $body));
        }
    }
}
