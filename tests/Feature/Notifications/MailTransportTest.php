<?php

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;

/**
 * The transport the mail actually leaves through.
 *
 * Everything else about mail is covered elsewhere — who gets one is
 * NotificationDeliveryTest's question, and what it says belongs to the
 * notification classes. This is the layer underneath both: that `resend` names a
 * real, resolvable transport rather than a string the container rejects the
 * first time a queued job tries to send.
 *
 * That failure mode is why this is worth a test at all. Snack's mail is queued,
 * so a missing driver never surfaces as a red request — it surfaces as a failed
 * job on a worker nobody is watching. The suite runs on the `array` mailer, so
 * without this nothing would ever touch the Resend configuration.
 */
it('resolves the resend mailer to the resend transport', function () {
    config()->set('services.resend.key', 're_test_key');

    expect(Mail::mailer('resend')->getSymfonyTransport())
        ->toBeInstanceOf(ResendTransport::class);
});

/**
 * An unset RESEND_API_KEY must not degrade into silently dropped mail.
 *
 * Deliberately asserted against Throwable rather than the TypeError the mail
 * manager happens to raise today: the invariant being pinned is that a
 * misconfigured key is loud, not which class the framework throws.
 */
it('refuses to build the resend mailer without a key', function () {
    config()->set('services.resend.key', null);

    // Caught by hand rather than with toThrow(): Throwable is an interface, and
    // Pest reads a non-class string as an expected message instead.
    $thrown = null;

    try {
        Mail::mailer('resend')->getSymfonyTransport();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(Throwable::class);
});
