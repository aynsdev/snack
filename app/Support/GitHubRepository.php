<?php

namespace App\Support;

/**
 * A project's GitHub repository link: github.com only, over https or ssh, and
 * stored as `https://github.com/<owner>/<repo>` so the agent dispatcher and the
 * daemon compare one spelling. Anything carrying userinfo, another host, or a
 * path beyond owner and repo is refused rather than repaired.
 */
final class GitHubRepository
{
    private const SEGMENT = '[A-Za-z0-9._-]+?';

    private const PATTERNS = [
        '#^https://github\.com/('.self::SEGMENT.')/('.self::SEGMENT.')(?:\.git)?$#',
        '#^git@github\.com:('.self::SEGMENT.')/('.self::SEGMENT.')\.git$#',
    ];

    public static function normalize(string $url): ?string
    {
        $url = trim($url);

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $url, $match) === 1 && ! self::isDots($match[1]) && ! self::isDots($match[2])) {
                return "https://github.com/{$match[1]}/{$match[2]}";
            }
        }

        return null;
    }

    /**
     * A branch name git and a shell both take literally: the rules of
     * `git check-ref-format --branch` that matter here, over a charset that
     * already excludes spaces, control characters and ~^:?*[\\.
     */
    public static function isSafeBranch(string $branch): bool
    {
        if (strlen($branch) > 200
            || preg_match('#^[A-Za-z0-9._/-]+$#', $branch) !== 1
            || str_contains($branch, '..')
            || str_starts_with($branch, '-')
            || str_ends_with($branch, '.')) {
            return false;
        }

        foreach (explode('/', $branch) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.') || str_ends_with($segment, '.lock')) {
                return false;
            }
        }

        return true;
    }

    private static function isDots(string $segment): bool
    {
        return trim($segment, '.') === '';
    }
}
