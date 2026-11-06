<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A caller's own name for a thread, mapped to the message that started it.
 *
 * An integration that narrates something with a lifecycle — a transaction, an
 * order, a deploy — wants the first event in the channel and every later one
 * underneath it. It already has an id for that thing; asking it to also store
 * Snack's message id, and to look it up before every update, is a database the
 * caller has to add for our sake. With `thread_key` the first delivery carrying
 * a key starts the thread and every later one with the same key replies in it.
 *
 * Scoped per hook rather than per channel: two integrations posting into one
 * room will both have an "order 42", and they mean different things.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_webhook_id')->constrained()->cascadeOnDelete();
            $table->string('key');

            // Cascades with the root: a thread whose first message is gone has
            // nothing to reply under, and the next delivery starts a fresh one.
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();

            $table->timestampsTz();

            $table->unique(['incoming_webhook_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_threads');
    }
};
