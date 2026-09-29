<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('starts_on');
            $table->date('ends_on');

            // Working days, excluding weekends and holidays, fixed at the time
            // of the request so a holiday added later does not rewrite history.
            $table->decimal('days', 5, 1);

            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestampsTz();

            $table->index(['workspace_id', 'status']);
            $table->index(['employee_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
