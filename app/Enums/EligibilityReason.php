<?php

namespace App\Enums;

/**
 * Why a task's assignee or label set changed — the `reason` of a
 * `task.eligibility_changed` event. Part of the integration contract.
 */
enum EligibilityReason: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case MemberRemoved = 'member_removed';
    case LabelDeleted = 'label_deleted';
    case LabelRenamed = 'label_renamed';
}
