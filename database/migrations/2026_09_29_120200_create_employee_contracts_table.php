<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 160);
            $table->string('employment_type', 20);
            $table->string('status', 20)->default('active');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();

            $table->decimal('salary_amount', 14, 2)->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('pay_frequency', 20)->nullable();

            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contracts');
    }
};
