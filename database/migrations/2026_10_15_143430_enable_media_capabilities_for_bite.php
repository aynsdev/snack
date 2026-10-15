<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switch image and video generation on for workspaces that saved their Bite
 * settings before those capabilities existed.
 *
 * A saved capability list is honoured exactly — see
 * App\Models\BiteSetting::enabledCapabilities() — so a capability added later
 * is off for every workspace whose admin ever pressed Save, and Bite tells
 * people it cannot make images. Generation is meant to be on wherever the
 * installation has a Magnific key, so it is added to those lists once, here.
 * An admin who switches it off afterwards stays switched off.
 */
return new class extends Migration
{
    private const ADDED = ['generate_image', 'generate_video'];

    public function up(): void
    {
        DB::table('bite_settings')
            ->whereNotNull('capabilities')
            ->orderBy('id')
            ->each(function (object $setting): void {
                $capabilities = json_decode((string) $setting->capabilities, true);

                if (! is_array($capabilities)) {
                    return;
                }

                DB::table('bite_settings')
                    ->where('id', $setting->id)
                    ->update([
                        'capabilities' => json_encode(array_values(array_unique([...$capabilities, ...self::ADDED]))),
                    ]);
            });
    }

    public function down(): void
    {
        DB::table('bite_settings')
            ->whereNotNull('capabilities')
            ->orderBy('id')
            ->each(function (object $setting): void {
                $capabilities = json_decode((string) $setting->capabilities, true);

                if (! is_array($capabilities)) {
                    return;
                }

                DB::table('bite_settings')
                    ->where('id', $setting->id)
                    ->update([
                        'capabilities' => json_encode(array_values(array_diff($capabilities, self::ADDED))),
                    ]);
            });
    }
};
