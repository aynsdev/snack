<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a personal connector token is for: an assistant a person drives by hand
 * (`token`), or an agent that works tickets on their behalf (`agent`).
 *
 * The token grants exactly the same access either way. The kind only changes how
 * what it does is labelled — an agent's messages carry `metadata.agent`, Bite
 * ignores them, and outbound events report them as `via: agent` — so a person
 * reading a thread can tell their colleague's words from their colleague's
 * agent's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->string('connector_kind', 16)->default('token')->after('workspace_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE oauth_access_tokens
            ADD CONSTRAINT oauth_access_tokens_connector_kind_check
            CHECK (connector_kind IN ('token', 'agent'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE oauth_access_tokens DROP CONSTRAINT oauth_access_tokens_connector_kind_check');

        Schema::table('oauth_access_tokens', function (Blueprint $table) {
            $table->dropColumn('connector_kind');
        });
    }
};
