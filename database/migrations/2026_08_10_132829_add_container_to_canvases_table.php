<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('canvases', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('workspace_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->after('channel_id')
                ->constrained()->cascadeOnDelete();
            $table->index(['channel_id', 'updated_at']);
            $table->index(['conversation_id', 'updated_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE canvases
            ADD CONSTRAINT canvases_one_container_check
            CHECK (channel_id IS NULL OR conversation_id IS NULL)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE canvases DROP CONSTRAINT IF EXISTS canvases_one_container_check');

        Schema::table('canvases', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'updated_at']);
            $table->dropIndex(['conversation_id', 'updated_at']);
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('channel_id');
        });
    }
};
