<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Working hours a workspace schedules people on. A shift whose end is not
 * after its start runs past midnight into the next day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->time('starts_at');
            $table->time('ends_at');
            // Minutes of slack before a clock-in counts as late or a clock-out as early.
            $table->unsignedSmallInteger('grace_minutes')->default(0);
            // ISO weekdays the shift is worked, 1 (Monday) to 7 (Sunday).
            $table->json('days');
            // The shift for anybody who has not been given one.
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['workspace_id', 'is_default']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
            $table->string('work_type', 20)->default('onsite')->after('employment_type');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            // The shift the entry was measured against, and how it measured up.
            $table->foreignId('shift_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('late_minutes')->nullable()->after('clock_out_within_geofence');
            $table->unsignedInteger('early_out_minutes')->nullable()->after('late_minutes');
            $table->unsignedInteger('overtime_minutes')->nullable()->after('early_out_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn(['late_minutes', 'early_out_minutes', 'overtime_minutes']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
            $table->dropColumn('work_type');
        });

        Schema::dropIfExists('shifts');
    }
};
