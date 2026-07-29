<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Snack leans on PostgreSQL-specific features rather than emulating them in PHP:
 *
 *  - citext:   case-insensitive uniqueness for emails, usernames, and channel
 *              slugs without functional indexes or `lower()` on every query.
 *  - pg_trgm:  trigram GIN indexes powering fuzzy user and channel autocomplete.
 *  - unaccent: folds diacritics so "José" matches "Jose" in full-text search.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $extensions = ['citext', 'pg_trgm', 'unaccent'];

    public function up(): void
    {
        foreach ($this->extensions as $extension) {
            DB::statement("CREATE EXTENSION IF NOT EXISTS \"{$extension}\"");
        }
    }

    public function down(): void
    {
        // Extensions are intentionally left installed: dropping them would break
        // any other schema in the same database that depends on them.
    }
};
