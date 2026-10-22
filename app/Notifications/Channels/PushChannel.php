<?php

namespace App\Notifications\Channels;

use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\Messages\PushMessage;
use App\Services\Push\ExpoPush;
use App\Services\Push\FirebaseMessaging;
use App\Services\Push\PushResult;
use Illuminate\Notifications\Notification;

/**
 * The `push` notification channel: one message to each of the person's
 * registered phones.
 *
 * The token decides the route. The Snack app is built with Expo and registers
 * an Expo push token, which goes through {@see ExpoPush}; a raw FCM
 * registration token goes straight to FCM through {@see FirebaseMessaging},
 * for a client built without Expo.
 *
 * A notification opts in by listing `push` in `via()` and answering `toPush()`.
 * Devices FCM reports as gone are deleted on the spot, so an uninstalled app
 * costs one failed send rather than one per notification forever.
 */
class PushChannel
{
    public function __construct(
        private readonly ExpoPush $expo,
        private readonly FirebaseMessaging $firebase,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toPush')) {
            return;
        }

        $message = $notification->toPush($notifiable);

        if (! $message instanceof PushMessage) {
            return;
        }

        foreach ($notifiable->pushDevices()->get() as $device) {
            if ($this->deliver($device, $message) === PushResult::InvalidToken) {
                $device->delete();
            }
        }
    }

    private function deliver(PushDevice $device, PushMessage $message): PushResult
    {
        if ($this->expo->handles($device)) {
            return $this->expo->send($device, $message);
        }

        // Kept rather than dropped when FCM is not set up: the token is fine,
        // and configuring FCM later should start reaching it.
        return $this->firebase->isConfigured()
            ? $this->firebase->send($device, $message)
            : PushResult::Failed;
    }
}
