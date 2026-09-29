<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clock-in / clock-out pairs, each end geotagged with where the browser said
 * the person was and whether that fell inside the workspace's geofence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->timestampTz('clock_in_at');
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->float('clock_in_accuracy')->nullable();
            $table->boolean('clock_in_within_geofence')->nullable();

            $table->timestampTz('clock_out_at')->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();
            $table->float('clock_out_accuracy')->nullable();
            $table->boolean('clock_out_within_geofence')->nullable();

            $table->text('note')->nullable();

            $table->timestampsTz();

            $table->index(['workspace_id', 'clock_in_at']);
            $table->index(['employee_id', 'clock_in_at']);
        });

        // One open shift per person: a second clock-in without a clock-out is
        // a double-click, not a second job.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX time_entries_one_open_per_employee
            ON time_entries (employee_id)
            WHERE clock_out_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
