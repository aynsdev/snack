<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_canvas_stars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canvas_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('message_attachment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestampsTz();

            $table->unique(['user_id', 'canvas_id']);
            $table->unique(['user_id', 'message_attachment_id']);
            $table->index(['workspace_id', 'user_id', 'updated_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE file_canvas_stars
            ADD CONSTRAINT file_canvas_stars_single_target_check
            CHECK (num_nonnulls(canvas_id, message_attachment_id) = 1)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('file_canvas_stars');
    }
};
