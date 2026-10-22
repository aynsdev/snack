<?php

namespace App\Notifications\Messages;

/**
 * What a notification shows on a phone's lock screen, and the data the app
 * reads when it is tapped.
 *
 * FCM only carries strings in `data`, so every value is cast here once rather
 * than at each call site. `thread` groups related pushes on the lock screen —
 * a busy DM stacks under one heading instead of filling the screen.
 */
class PushMessage
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $thread = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function stringData(): array
    {
        return array_map(
            fn ($value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            array_filter($this->data, fn ($value): bool => $value !== null),
        );
    }
}
