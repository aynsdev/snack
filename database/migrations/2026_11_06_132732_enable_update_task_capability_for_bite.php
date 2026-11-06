<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Switch task updates on for workspaces that saved their Bite settings before
 * the capability existed.
 *
 * A saved capability list is honoured exactly — see
 * App\Models\BiteSetting::enabledCapabilities() — so without this, every
 * workspace whose admin ever pressed Save would have Bite answer "assign this
 * to Renz" with "I can't edit tasks". It asks for approval like create_task,
 * so it is added to those lists once, here. An admin who switches it off
 * afterwards stays switched off.
 */
return new class extends Migration
{
    private const ADDED = ['update_task'];

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
