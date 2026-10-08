<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company equipment: what there is, who has it, and who has asked for some.
 *
 * Every hand-over is an assignment row that stays when the asset comes back,
 * which is what gives each item its history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            // The company's own label on the thing, e.g. LAP-0042.
            $table->string('tracking_code', 60)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->date('purchased_on')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('status', 20)->default('available');
            $table->date('warranty_expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'tracking_code']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('equipment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('assigned_on');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition_out', 500)->nullable();
            // Set when the holder asks to give it back.
            $table->timestampTz('return_requested_at')->nullable();
            $table->date('returned_on')->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('condition_in', 500)->nullable();
            $table->timestampsTz();

            $table->index(['employee_id', 'returned_on']);
        });

        // One holder at a time: an item out with somebody cannot be handed out again.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX equipment_assignments_one_open_per_item
            ON equipment_assignments (equipment_id)
            WHERE returned_on IS NULL
        SQL);

        Schema::create('equipment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_category_id')->constrained()->cascadeOnDelete();
            $table->text('reason')->nullable();
            // The hand-over made when HR approved and allocated something.
            $table->foreignId('equipment_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'status']);
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_requests');
        Schema::dropIfExists('equipment_assignments');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('equipment_categories');
    }
};
