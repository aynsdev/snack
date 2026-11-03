<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional narrowing of a standup's roster.
 *
 * Null keeps the original rule — everyone in the channel is asked. A list of
 * user ids asks only those people, and is still intersected with the channel's
 * live membership on every run, so somebody who leaves the channel stops being
 * asked even though their id remains here. The channel stays the upper bound
 * of the roster; this column can only ever remove people from it, never add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standups', function (Blueprint $table) {
            $table->jsonb('participant_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('standups', function (Blueprint $table) {
            $table->dropColumn('participant_ids');
        });
    }
};
