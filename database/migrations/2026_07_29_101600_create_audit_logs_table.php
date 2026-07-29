<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only record of workspace administration.
 *
 * `user_id` is nullable so that a log entry survives the deletion of the actor,
 * which is precisely when the audit trail matters most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 64);
            $table->nullableMorphs('auditable');

            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestampsTz();

            // The admin audit view: one workspace, newest first.
            $table->index(['workspace_id', 'created_at']);

            // "What happened to this member/channel?"
            $table->index(['workspace_id', 'action', 'created_at']);
        });

        DB::statement('CREATE INDEX audit_logs_actor_index ON audit_logs (user_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
