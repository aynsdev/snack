<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every image and video Bite has been asked to make.
 *
 * # Why a row rather than only the message
 *
 * The result is posted as an ordinary message with an attachment, which is
 * what makes it appear in the chat and in Files. But a generation is also a
 * paid, minutes-long job with a state of its own — submitted, waiting on
 * Magnific, done or failed — and the Bite page's Media tab lists them per
 * person. Neither of those can be read back off a message.
 *
 * # Who it belongs to
 *
 * `user_id` is the person who asked, not the bot that posted. The Media tab
 * shows a person their own generations, and the result message being posted by
 * Bite does not make it Bite's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bite_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The message that asked. Its container and thread are where the
            // result is posted. Nulled if that message is deleted: the image
            // is still theirs.
            $table->foreignId('request_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->string('kind', 10);
            $table->string('model', 40);
            $table->text('prompt');
            $table->string('aspect_ratio', 10)->nullable();
            $table->unsignedSmallInteger('duration')->nullable();

            // The image a video was made from, when there was one.
            $table->foreignId('source_attachment_id')->nullable()->constrained('message_attachments')->nullOnDelete();

            $table->string('status', 20)->default('pending');
            $table->string('task_id')->nullable();
            $table->text('error')->nullable();

            $table->foreignId('message_attachment_id')->nullable()->constrained('message_attachments')->nullOnDelete();
            $table->foreignId('result_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'user_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE bite_generations
            ADD CONSTRAINT bite_generations_kind_check CHECK (kind IN ('image', 'video'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE bite_generations
            ADD CONSTRAINT bite_generations_status_check
            CHECK (status IN ('pending', 'processing', 'completed', 'failed'))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('bite_generations');
    }
};
