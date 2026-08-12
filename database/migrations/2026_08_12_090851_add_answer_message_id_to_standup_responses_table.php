<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The channel message carrying this person's answers.
 *
 * # Why the answers reach the channel before the run closes
 *
 * A standup answered at 09:31 was worth reading at 09:31. Holding every answer
 * until the last person replies — or until the close window expires four hours
 * later — is what turns a standup into a report nobody opens: by the time it
 * lands, the person who was blocked has either been unblocked by somebody else
 * or has spent the morning stuck. So each answer is posted as it arrives, and
 * the run's summary at close becomes the roll-up of who spoke and who did not.
 *
 * # Why the id is stored rather than inferred
 *
 * It is the idempotence guard. Answering is not structurally once-only — the
 * policy allows a response to be submitted again while the run is open, and a
 * retry, a double-tap, or a reply that races the card would otherwise put the
 * same update in the channel twice. With the id on the row, a second submission
 * edits the message that is already there instead of posting a new one.
 *
 * `nullOnDelete`: deleting the channel message must not delete the answers it
 * was made from. The response is the record; the message is a rendering of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standup_responses', function (Blueprint $table) {
            $table->foreignId('answer_message_id')->nullable()->after('prompt_message_id')
                ->constrained('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('standup_responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answer_message_id');
        });
    }
};
