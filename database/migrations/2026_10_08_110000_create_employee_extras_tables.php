<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paperwork HR collects, discipline HR issues, and the policies everybody
 * reads.
 *
 * A document request goes to several people at once; each of them gets their
 * own submission row, which is what they upload to and HR approves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            // File extensions accepted, e.g. ["pdf", "jpg"].
            $table->json('allowed_formats');
            $table->unsignedSmallInteger('max_size_mb')->default(10);
            $table->date('due_on')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('document_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('file_path')->nullable();
            $table->string('file_name', 200)->nullable();
            // When the document itself stops being valid — a permit, an ID.
            $table->date('expires_on')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();

            $table->unique(['document_request_id', 'employee_id']);
            $table->index(['employee_id', 'status']);
        });

        Schema::create('disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->text('description');
            $table->date('starts_on');
            // How long a suspension lasts; null for actions with no duration.
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->timestampsTz();

            $table->index(['employee_id', 'starts_on']);
            $table->index(['workspace_id', 'starts_on']);
        });

        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->string('file_path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->boolean('requires_acknowledgement')->default(false);
            $table->timestampsTz();

            $table->index(['workspace_id', 'title']);
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('acknowledged_at');

            $table->unique(['company_policy_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('company_policies');
        Schema::dropIfExists('disciplinary_actions');
        Schema::dropIfExists('document_submissions');
        Schema::dropIfExists('document_requests');
    }
};
