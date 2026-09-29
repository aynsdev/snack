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
