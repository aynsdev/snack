<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing human member becomes an employee, hired on the day they
 * joined. New members get their row from WorkspaceMember's `created` hook.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO employees (workspace_id, user_id, workspace_member_id, employment_type, status, hired_on, created_at, updated_at)
            SELECT wm.workspace_id, wm.user_id, wm.id, 'full_time', 'active', wm.joined_at::date, NOW(), NOW()
            FROM workspace_members wm
            JOIN users u ON u.id = wm.user_id
            WHERE u.is_bot = false
            ON CONFLICT (workspace_id, user_id) DO NOTHING
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO employment_histories (employee_id, change, effective_on, job_title, department, employment_type, created_at, updated_at)
            SELECT e.id, 'hired', e.hired_on, e.job_title, e.department, e.employment_type, NOW(), NOW()
            FROM employees e
            WHERE NOT EXISTS (SELECT 1 FROM employment_histories h WHERE h.employee_id = e.id)
        SQL);
    }

    public function down(): void
    {
        // The rows are ordinary employee records by now; nothing to undo.
    }
};
