<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The transactional outbox for events Snack sends to the agent dispatcher.
 *
 * A row is written in the same transaction as the change it describes, so an
 * event exists if and only if the change committed. Delivery happens afterwards,
 * from a queued job and a sweeper — see App\Jobs\DeliverOutboundEvent.
 *
 * # The sequence
 *
 * `id` is only a key: bigserial values are handed out before commit, so they
 * leave gaps on rollback and can commit out of order. The envelope's `sequence`
 * comes from `outbound_sequences` instead — one counter row per workspace,
 * incremented inside the same transaction as the outbox insert. The row stays
 * locked until that transaction commits, so a workspace's sequence numbers
 * commit in order and a rollback takes its number with it: the receiver sees a
 * gapless, commit-ordered stream it can apply strictly in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_sequences', function (Blueprint $table) {
            $table->foreignId('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('value')->default(0);
        });

        Schema::create('outbound_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'sequence']);
        });

        // The sweeper's one question — what is pending and due — over the only
        // rows it ever asks about. Delivered and dead rows are most of the table.
        DB::statement(<<<'SQL'
            CREATE INDEX outbound_events_due_index
            ON outbound_events (available_at)
            WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_events');
        Schema::dropIfExists('outbound_sequences');
    }
};
