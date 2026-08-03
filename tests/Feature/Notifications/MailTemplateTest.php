<?php

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The chrome every email is wrapped in.
 *
 * What each mail *says* belongs to its own notification and is covered with the
 * feature that sends it. This is the shared layer underneath: the published
 * templates in resources/views/vendor/mail and the palette they are styled with.
 *
 * Those templates are a vendor:publish away from being silently reset to
 * Laravel's stock versions, which is the failure this guards. A reset would not
 * break a single existing test — every mail would still send, still say the
 * right thing, and still pass — it would just quietly go out wearing somebody
 * else's branding.
 */

/**
 * A minimal mail with every branded region present: header, body, button,
 * footer, and the subcopy that only appears alongside an action.
 */
function renderedMail(): string
{
    return (new MailMessage)
        ->greeting('Hello.')
        ->line('The body.')
        ->action('Open Snack', 'http://snack.test/open')
        ->render();
}

it('brands the header with the Snack mark, wordmark, and tagline', function () {
    $html = renderedMail();

    expect($html)
        ->toContain('snack-mark.png')
        ->toContain('Snack')
        ->toContain('Small chats. Big work.');
});

/**
 * The mark is referenced by URL, not embedded, so mail clients need an absolute
 * one. A relative src resolves against the client's own origin and 404s.
 */
it('links the mark absolutely', function () {
    expect(renderedMail())->toContain(asset('snack-mark.png'))
        ->and(asset('snack-mark.png'))->toStartWith(config('app.url'));
});

it('ships the mark the header points at', function () {
    expect(public_path('snack-mark.png'))->toBeFile();
});

/**
 * The palette, pinned to the `original` colour theme in resources/css/app.css.
 * The button is the one element whose colour genuinely matters.
 */
it('paints the primary button in the brand orange', function () {
    expect(renderedMail())
        ->toContain('background-color: #f97316')
        // #171717 rather than white: 6.40:1 against the orange instead of 2.89:1.
        ->toContain('color: #171717');
});

it('leaves no trace of the stock Laravel theme', function () {
    $html = renderedMail();

    expect($html)
        ->not->toContain('laravel.com')
        ->not->toContain('Laravel Logo')
        // The framework's greys, replaced wholesale by the app.css palette.
        ->not->toContain('#18181b')
        ->not->toContain('#e4e4e7');
});

/**
 * The tagline moved into the header, so any greeting that still said it would
 * print the same six words twice on one screen.
 */
it('does not repeat the tagline in the invitation greeting', function () {
    $invitation = WorkspaceInvitation::factory()->create(['role' => WorkspaceRole::Member]);

    // Cast because render() hands back an HtmlString, which expect() reads as an
    // object rather than as the string every assertion below wants.
    $html = (string) (new WorkspaceInvitationNotification($invitation, $invitation->plainToken))
        ->toMail(new User(['name' => 'Grace', 'email' => 'grace@snack.test']))
        ->render();

    expect(substr_count($html, 'Small chats. Big work.'))->toBe(1)
        ->and($html)->toContain("You're invited.");
});
