<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('change', 20);
            $table->date('effective_on');
            $table->string('job_title', 120)->nullable();
            $table->string('department', 120)->nullable();
            $table->string('employment_type', 20)->nullable();
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_histories');
    }
};
