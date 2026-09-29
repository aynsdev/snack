<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR side of a workspace membership.
 *
 * One row per person per workspace, created alongside the membership. The link
 * to `workspace_members` is nullable so the employment record survives the
 * person being removed from the workspace — HR history is not something a
 * membership change should erase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->string('employee_number', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('employment_type', 20)->default('full_time');
            $table->string('status', 20)->default('active');

            $table->date('hired_on')->nullable();
            $table->date('terminated_on')->nullable();

            $table->date('birth_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('emergency_contact_name', 120)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();

            $table->timestampsTz();

            $table->unique(['workspace_id', 'user_id']);
            $table->unique(['workspace_id', 'employee_number']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
