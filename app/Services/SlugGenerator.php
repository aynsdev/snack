<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Produces the URL-safe, case-insensitively unique slugs used by workspaces
 * (`/w/{slug}`) and channels (`#{slug}`).
 *
 * Uniqueness is also enforced by database constraints; this class exists to
 * pick a pleasant slug rather than to guarantee correctness, so callers should
 * still be prepared for a constraint violation under concurrency.
 */
class SlugGenerator
{
    private const MAX_ATTEMPTS = 50;

    /**
     * A globally unique workspace slug, avoiding first-party route prefixes.
     */
    public function forWorkspace(string $name, ?int $ignoreId = null): string
    {
        $reserved = array_map('strtolower', config('snack.workspaces.reserved_slugs', []));

        return $this->unique(
            base: $this->normalize($name, fallback: 'workspace'),
            isTaken: function (string $slug) use ($reserved, $ignoreId): bool {
                if (in_array($slug, $reserved, true)) {
                    return true;
                }

                return Workspace::query()
                    ->where('slug', $slug)
                    ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                    ->exists();
            },
        );
    }

    /**
     * A channel slug, unique within its workspace.
     */
    public function forChannel(Workspace $workspace, string $name, ?int $ignoreId = null): string
    {
        $reserved = array_map('strtolower', config('snack.channels.reserved_names', []));

        return $this->unique(
            base: $this->normalize($name, fallback: 'channel'),
            isTaken: function (string $slug) use ($workspace, $reserved, $ignoreId): bool {
                if (in_array($slug, $reserved, true)) {
                    return true;
                }

                return Channel::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('slug', $slug)
                    ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                    ->exists();
            },
        );
    }

    /**
     * A username, unique across the installation.
     */
    public function forUsername(string $name): string
    {
        return $this->unique(
            base: $this->normalize($name, fallback: 'member'),
            isTaken: fn (string $slug): bool => User::query()->where('username', $slug)->exists(),
        );
    }

    /**
     * Lower-cased, ASCII, hyphen-separated, and trimmed to the configured
     * maximum channel name length.
     */
    private function normalize(string $value, string $fallback): string
    {
        $slug = Str::of($value)->ascii()->slug('-')->limit(
            (int) config('snack.channels.name_max_length', 80),
            end: '',
        )->trim('-')->toString();

        return $slug === '' ? $fallback : $slug;
    }

    /**
     * Append -2, -3, … until the slug is free, then fall back to a random
     * suffix so this can never loop indefinitely.
     *
     * @param  callable(string): bool  $isTaken
     */
    private function unique(string $base, callable $isTaken): string
    {
        if (! $isTaken($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= self::MAX_ATTEMPTS; $suffix++) {
            $candidate = "{$base}-{$suffix}";

            if (! $isTaken($candidate)) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }
}
