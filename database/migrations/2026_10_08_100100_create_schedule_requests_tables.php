<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests an employee files and HR approves: a different shift, a different
 * work type, or a correction to their attendance.
 *
 * A shift or work-type request without an end date is permanent; with one it
 * covers only those days and lapses on its own afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('reason')->nullable();
            $this->review($table);
        });

        Schema::create('work_type_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('work_type', 20);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('reason')->nullable();
            $this->review($table);
        });

        Schema::create('attendance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The entry to correct; null asks for a missing one to be added.
            $table->foreignId('time_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->timestampTz('clock_in_at');
            $table->timestampTz('clock_out_at');
            $table->text('reason');
            $this->review($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_requests');
        Schema::dropIfExists('work_type_requests');
        Schema::dropIfExists('shift_requests');
    }

    private function review(Blueprint $table): void
    {
        $table->string('status', 20)->default('pending');
        $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestampTz('reviewed_at')->nullable();
        $table->text('review_note')->nullable();
        $table->timestampsTz();

        $table->index(['workspace_id', 'status']);
        $table->index(['employee_id', 'status']);
    }
};
