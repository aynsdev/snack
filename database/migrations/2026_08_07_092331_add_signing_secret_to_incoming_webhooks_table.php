<?php

use App\Services\WebhookSignature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional second factor for a webhook: prove you hold the secret, not just
 * the URL.
 *
 * The table's own note explains why the URL alone is an acceptable credential —
 * one channel, a visible bot, nothing readable. That reasoning holds, and it is
 * still the default. But it rests entirely on the URL not leaking, and a URL is
 * a thing that ends up in CI logs, in `.env` files committed by accident, in
 * screenshots, and in the scrollback of whoever was pairing that afternoon.
 *
 * With a secret set, a stolen URL is worth nothing on its own. The caller signs
 * the exact bytes it sends, together with a timestamp, and Snack recomputes the
 * same HMAC — see {@see WebhookSignature} for the scheme and for
 * why the timestamp is inside the signed string rather than beside it.
 *
 * # Why optional rather than always on
 *
 * Because the caller is often three lines of curl, and a scheme that cannot be
 * satisfied by three lines of curl is a scheme people work around — by checking
 * the secret into the repo, or by not adopting webhooks at all. Making it opt-in
 * per hook lets the deploy notification stay trivial while the hook that posts
 * customer names into a private channel is signed.
 *
 * # Why one column and not a table
 *
 * There is one live secret per hook. Supporting several at once would buy
 * zero-downtime rotation — sign with the new, accept either for an hour — which
 * sounds prudent and is not worth a table here: rotating a webhook secret is a
 * two-line config change on the calling side, done deliberately, by the person
 * who just clicked the button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            /*
             * Null means "unsigned" — the URL is the whole credential, which is
             * the default and what every hook created before this migration
             * keeps doing.
             *
             * Encrypted at rest for the reason `token` is: the admin screen has
             * to be able to show it again, since there is nowhere else it could
             * come from, and a leaked database without APP_KEY must not hand
             * over the thing that makes a leaked URL usable. Storing a hash
             * instead is not an option — verifying an HMAC requires the secret
             * itself, not a digest of it.
             */
            $table->text('signing_secret')->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_webhooks', function (Blueprint $table) {
            $table->dropColumn('signing_secret');
        });
    }
};
