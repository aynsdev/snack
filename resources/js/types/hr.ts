/**
 * Mirrors the resources in App\Http\Resources\Hr.
 */

export type Option = { value: string; label: string };

export type PersonSummary = {
    id: number;
    name: string;
    initials: string;
    avatar_url: string | null;
};

export type Employee = {
    id: number;
    user: {
        id: number;
        name: string;
        username: string;
        initials: string;
        avatar_url: string | null;
    };
    email: string;
    workspace_role: string | null;
    employee_number: string | null;
    job_title: string | null;
    department: string | null;
    employment_type: string;
    employment_type_label: string;
    work_type: string;
    work_type_label: string;
    shift?: { id: number; name: string; hours: string } | null;
    status: string;
    status_label: string;
    hired_on: string | null;
    terminated_on: string | null;
    birth_date: string | null;
    phone: string | null;
    address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    manager?: { id: number; name: string } | null;
    is_clocked_in?: boolean;
};

export type EmploymentHistoryEntry = {
    id: number;
    change: string;
    change_label: string;
    effective_on: string;
    job_title: string | null;
    department: string | null;
    employment_type_label: string | null;
    notes: string | null;
    recorded_by: string | null;
};

export type Contract = {
    id: number;
    title: string;
    employment_type: string;
    employment_type_label: string;
    status: string;
    effective_status: string;
    effective_status_label: string;
    starts_on: string;
    ends_on: string | null;
    salary_amount: string | null;
    salary_currency: string | null;
    pay_frequency: string | null;
    notes: string | null;
    file_name: string | null;
    has_file: boolean;
};

export type EmployeeRecord = {
    id: number;
    type: string;
    type_label: string;
    title: string;
    body: string | null;
    recorded_on: string;
    recorded_by: string | null;
    file_name: string | null;
    has_file: boolean;
};

export type Absence = {
    id: number;
    date: string;
    type: string;
    type_label: string;
    note: string | null;
    recorded_by: string | null;
};

export type LeaveType = {
    id: number;
    name: string;
    color: string;
    days_per_year: number | null;
    is_paid: boolean;
    is_archived: boolean;
};

export type LeaveBalance = {
    leave_type_id: number;
    name: string;
    color: string;
    allowance: number | null;
    used: number;
    remaining: number | null;
};

export type LeaveRequest = {
    id: number;
    employee: PersonSummary & { job_title: string | null };
    leave_type: LeaveType;
    starts_on: string;
    ends_on: string;
    days: number;
    reason: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'cancelled';
    status_label: string;
    reviewer: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    created_at: string | null;
    can: { cancel: boolean; review: boolean };
};

export type GeoStamp = {
    latitude: number;
    longitude: number;
    accuracy: number | null;
    within_geofence: boolean | null;
};

export type TimeEntry = {
    id: number;
    employee?: PersonSummary;
    clock_in_at: string;
    clock_out_at: string | null;
    minutes: number;
    is_open: boolean;
    clock_in_location: GeoStamp | null;
    clock_out_location: GeoStamp | null;
    late_minutes: number | null;
    early_out_minutes: number | null;
    overtime_minutes: number | null;
    note: string | null;
};

export type Geofence = {
    latitude: number;
    longitude: number;
    radius_meters: number;
    enforced: boolean;
};

export type Holiday = {
    id: number;
    name: string;
    date: string;
    is_recurring: boolean;
    next_on: string | null;
};

export type Announcement = {
    id: number;
    title: string;
    body: string;
    is_pinned: boolean;
    notified_everyone: boolean;
    channel: { name: string; slug: string } | null;
    is_posted: boolean;
    author: Omit<PersonSummary, 'id'> | null;
    created_at: string | null;
    updated_at: string | null;
};

export type Paginated<T> = {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
    links: { next: string | null; prev: string | null };
};

/** Mirrors the shared `clock` prop from HandleInertiaRequests::clockProps(). */
export type HeaderClockState = {
    /** The viewer's own employee file in this workspace. */
    employee_id: number;
    open_entry: { id: number; clock_in_at: string } | null;
    require_location: boolean;
    geofence: Geofence | null;
};

export type Shift = {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string;
    hours: string;
    grace_minutes: number;
    /** ISO weekdays, 1 (Monday) to 7 (Sunday). */
    days: number[];
    is_default: boolean;
};

export type HrRequestType = 'shift' | 'work_type' | 'attendance';

export type HrRequest = {
    id: number;
    type: HrRequestType;
    kind: string;
    summary: string;
    employee: PersonSummary;
    reason: string | null;
    status: string;
    status_label: string;
    reviewer: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    created_at: string | null;
    can: { cancel: boolean; review: boolean };
};

export type DocumentSubmission = {
    id: number;
    employee?: PersonSummary;
    request?: {
        id: number;
        title: string;
        description: string | null;
        allowed_formats: string[];
        max_size_mb: number;
        due_on: string | null;
    };
    status: 'pending' | 'submitted' | 'approved' | 'rejected';
    status_label: string;
    file_name: string | null;
    has_file: boolean;
    expires_on: string | null;
    submitted_at: string | null;
    reviewer: string | null;
    review_note: string | null;
    can: { upload: boolean; review: boolean };
};

export type DocumentRequest = {
    id: number;
    title: string;
    description: string | null;
    allowed_formats: string[];
    max_size_mb: number;
    due_on: string | null;
    created_by: string | null;
    created_at: string | null;
    total: number;
    uploaded: number;
    approved: number;
    submissions: DocumentSubmission[];
};

export type DisciplinaryAction = {
    id: number;
    type: string;
    type_label: string;
    description: string;
    starts_on: string;
    duration_days: number | null;
    ends_on: string | null;
    issued_by: string | null;
    file_name: string | null;
    has_file: boolean;
};

export type CompanyPolicy = {
    id: number;
    title: string;
    body: string;
    requires_acknowledgement: boolean;
    file_name: string | null;
    has_file: boolean;
    author: string | null;
    updated_at: string | null;
    acknowledged: boolean;
    acknowledged_count: number | null;
    can: { acknowledge: boolean };
};

export type ChecklistAssignee = 'hr' | 'manager' | 'employee';

export type ChecklistTask = {
    id: number;
    title: string;
    description: string | null;
    assignee_role: ChecklistAssignee;
    assignee: string | null;
    due_on: string | null;
    is_overdue: boolean;
    completed_at: string | null;
    completed_by: string | null;
    checklist?: {
        id: number;
        kind: 'onboarding' | 'offboarding';
        kind_label: string;
        employee: string;
    };
    can_toggle: boolean;
};

export type EmployeeChecklist = {
    id: number;
    kind: 'onboarding' | 'offboarding';
    kind_label: string;
    name: string;
    status: string;
    status_label: string;
    employee: PersonSummary & { job_title: string | null };
    starts_on: string;
    target_on: string | null;
    completed_at: string | null;
    done: number;
    total: number;
    tasks: ChecklistTask[];
    can_close: boolean;
};

export type ChecklistTemplate = {
    id: number;
    kind: 'onboarding' | 'offboarding';
    name: string;
    is_default: boolean;
    tasks: {
        title: string;
        description: string | null;
        assignee_role: ChecklistAssignee;
        due_offset_days: number;
    }[];
};

export type Resignation = {
    id: number;
    employee: PersonSummary;
    reason: string;
    last_day_on: string;
    status: string;
    status_label: string;
    reviewer: string | null;
    review_note: string | null;
    created_at: string | null;
    can: { withdraw: boolean; review: boolean };
};
