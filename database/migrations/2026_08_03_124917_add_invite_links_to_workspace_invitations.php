<?php

use App\Models\WorkspaceInvitation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shareable invite links.
 *
 * An invite link is the same row as an emailed invitation with `email` left
 * null: same token, same URL, same acceptance path, same revocation. Modelling
 * it as a second table would have meant a second token scheme, a second landing
 * page, and a second set of expiry rules free to drift out of step with the
 * first — all to express "an invitation nobody is named on".
 *
 * The two differences are exactly what `email IS NULL` buys:
 *
 * - **It is addressed to nobody**, so a forwarded link is the point rather than
 *   an attack. {@see WorkspaceInvitation::isAddressedTo()} answers
 *   true for it, and registration stops binding to an address.
 * - **It survives redemption**, so `accepted_at` cannot be its counter.
 *   `use_count` is, and `max_uses` bounds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            /*
             * How many times the link may still be redeemed. Null is unlimited
             * — bounded only by `expires_at` — which is what most people mean
             * by "invite link". Always null for an emailed invitation, which is
             * single-use by way of `accepted_at`.
             */
            $table->unsignedInteger('max_uses')->nullable()->after('role');
            $table->unsignedInteger('use_count')->default(0)->after('max_uses');

            /*
             * The link's own plaintext token, encrypted at rest.
             *
             * `token` stays a SHA-256 hash and remains the only thing lookups
             * touch. This column exists because a link, unlike an email, has no
             * delivery mechanism other than the admin screen: "copy invite link"
             * has to keep working next week, and a hash cannot be un-hashed.
             *
             * Encrypted rather than plain so the property the hash was giving us
             * is not simply thrown away — a leaked database without APP_KEY
             * still cannot be replayed into workspace access. Null for emailed
             * invitations, whose plaintext genuinely only ever exists in the
             * message that carried it.
             */
            $table->text('link_token')->nullable()->after('token');
        });

        // An invite link names nobody.
        DB::statement('ALTER TABLE workspace_invitations ALTER COLUMN email DROP NOT NULL');

        /*
         * One live link per workspace, so "the invite link" is an unambiguous
         * thing an admin can copy, revoke, and regenerate. Issuing a new one
         * revokes the old, exactly as re-inviting an address does.
         *
         * A separate index rather than a widening of the per-email one:
         * Postgres treats NULLs as distinct in a unique index, so the existing
         * `(workspace_id, email)` constraint would happily allow a hundred live
         * links and never say a word.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workspace_invitations_live_link_unique
            ON workspace_invitations (workspace_id)
            WHERE email IS NULL AND accepted_at IS NULL AND revoked_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS workspace_invitations_live_link_unique');

        // Links have no address to fall back on, so they cannot survive the
        // column becoming NOT NULL again.
        DB::table('workspace_invitations')->whereNull('email')->delete();

        DB::statement('ALTER TABLE workspace_invitations ALTER COLUMN email SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_invitations
            DROP COLUMN IF EXISTS max_uses,
            DROP COLUMN IF EXISTS use_count,
            DROP COLUMN IF EXISTS link_token
        SQL);
    }
};
