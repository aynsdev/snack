<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web links on a card — a pull request, a design, a Sentry issue.
 *
 * Their own rows rather than lines in the description, so they can be listed,
 * opened, and removed as links, and so writing the description never loses
 * one. A card keeps a link to the same URL once.
 *
 * `created_by` is `nullOnDelete`: a pull request somebody linked is still the
 * pull request after they leave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('url', 2048);
            $table->string('title', 200)->nullable();
            $table->timestampsTz();

            $table->unique(['task_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
