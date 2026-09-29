<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            $table->string('color', 20)->default('sky');

            // Null means the allowance is not tracked (e.g. unpaid leave).
            $table->decimal('days_per_year', 5, 1)->nullable();
            $table->boolean('is_paid')->default(true);
            $table->timestampTz('archived_at')->nullable();

            $table->timestampsTz();

            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
