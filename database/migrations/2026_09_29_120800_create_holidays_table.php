<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->date('date');

            // Falls on the same month and day every year.
            $table->boolean('is_recurring')->default(false);

            $table->timestampsTz();

            $table->unique(['workspace_id', 'date', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
