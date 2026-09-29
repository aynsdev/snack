<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('type', 30);
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->date('recorded_on');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();

            $table->timestampsTz();

            $table->index(['employee_id', 'recorded_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_records');
    }
};
