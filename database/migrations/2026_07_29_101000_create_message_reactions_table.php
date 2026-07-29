<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Stores the emoji shortcode (e.g. "taco"), not the rendered glyph,
            // so the same reaction survives an emoji set change.
            $table->string('emoji', 64);

            $table->timestampsTz();

            // One of each emoji per person per message; also the lookup used to
            // toggle a reaction off.
            $table->unique(['message_id', 'user_id', 'emoji']);

            // Grouping reactions when rendering a message.
            $table->index(['message_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
