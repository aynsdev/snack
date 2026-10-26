<?php

use App\Support\GitHubRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The GitHub repository a project's code lives in, normalised by
 * {@see GitHubRepository}. Read by the Kusina daemon to find
 * the checkout an agent works in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('repository_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('repository_url');
        });
    }
};
