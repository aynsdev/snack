<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the version actually went out.
 *
 * A timestamp rather than a `status` column, for the reason sprints have no
 * state column either: released is exactly "this has a date", and storing the
 * word as well gives the releases table two things to believe. {@see
 * App\Enums\ReleaseState} derives the status from this.
 *
 * It is also what makes "overdue" answerable without counting anything: a
 * release is late when the day it was meant to go out has passed and it has not
 * gone out. Whether the work inside it is finished is a different question, and
 * the progress bar is where that is asked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->timestampTz('released_at')->nullable()->after('ends_on');
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
