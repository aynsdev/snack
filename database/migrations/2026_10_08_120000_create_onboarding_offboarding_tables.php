<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding and offboarding as checklists.
 *
 * A template is the workspace's list of steps — who does each, and how many
 * days from the start (onboarding) or before the last day (offboarding) it is
 * due. Starting one for a person copies its steps onto their own checklist, so
 * editing a template never rewrites a checklist already under way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name', 120);
            // The template used when nobody picks one: on a resignation, or
            // when a new member joins with automatic onboarding on.
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['workspace_id', 'kind']);
        });

        Schema::create('checklist_template_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            // hr, manager, or employee: resolved to a person when started.
            $table->string('assignee_role', 20);
            // Days after the start (onboarding) or relative to the last day
            // (offboarding, usually negative).
            $table->smallInteger('due_offset_days')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
        });

        Schema::create('employee_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name', 120);
            $table->string('status', 20)->default('in_progress');
            $table->date('starts_on');
            // The first day at work, or the last.
            $table->date('target_on')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'kind', 'status']);
            $table->index(['employee_id', 'kind']);
        });

        Schema::create('employee_checklist_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_checklist_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('assignee_role', 20);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->index(['assignee_id', 'completed_at']);
        });

        Schema::create('resignations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->date('last_day_on');
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
        Schema::dropIfExists('resignations');
        Schema::dropIfExists('employee_checklist_tasks');
        Schema::dropIfExists('employee_checklists');
        Schema::dropIfExists('checklist_template_tasks');
        Schema::dropIfExists('checklist_templates');
    }
};
