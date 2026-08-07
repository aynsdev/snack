<?php

namespace App\Http\Requests\Webhooks;

/**
 * The fields that decide what a hook is called and what it looks like.
 *
 * Shared by the create and update requests so the two cannot drift: a name the
 * form accepts on creation and rejects on edit is a hook an admin can make and
 * then never save again.
 */
trait ValidatesWebhookIdentity
{
    /**
     * @return array<string, mixed>
     */
    protected function identityRules(): array
    {
        return [
            // What the admin screen lists it as, and what its bot account is
            // named in the channel. Bounded to the same 80 characters a channel
            // name gets, because it is read in the same places.
            'name' => ['required', 'string', 'min:1', 'max:80'],

            /*
             * The default display name, when the hook should post as something
             * other than what it is called in the admin list — "CI" in the
             * channel, "Jenkins (staging)" on the settings screen. Nullable,
             * which is what nearly every hook wants.
             */
            'default_username' => ['nullable', 'string', 'max:80'],

            /*
             * An emoji shortcode without its colons. Validated against the same
             * pattern {@see App\Support\WebhookPayload::icon()} accepts from a
             * payload, so a hook's default and a per-delivery override cannot
             * disagree about what an icon is.
             */
            'default_icon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_+-]+$/i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function identityMessages(): array
    {
        return [
            'name.required' => 'Give the webhook a name so you can tell it from the others.',
            'default_icon.regex' => 'An icon is an emoji shortcode, like rocket or white_check_mark.',
        ];
    }
}
