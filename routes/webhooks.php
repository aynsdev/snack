<?php

use App\Http\Controllers\Webhooks\WebhookDeliveryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Incoming webhooks
|--------------------------------------------------------------------------
|
| The one machine-facing endpoint in Snack: an outside system posts JSON here
| and it becomes a message in one channel.
|
| Registered in its own file, outside the `web` group, and that is the whole
| reason the file exists. A route in web.php would pick up sessions, cookies,
| and CSRF verification — three things a curl call from a deploy script has none
| of, and the last of which would reject every delivery. It would also hand a
| session cookie to a CI runner on every build, which is a session that exists
| for no one.
|
| Deliberately outside the workspace prefix too. The URL is pasted into a config
| file and expected to keep working, and a workspace slug in the path would break
| every integration in the building the day somebody renames the workspace. The
| token already names the hook, and the hook names its workspace.
|
*/

Route::post('hooks/{token}', [WebhookDeliveryController::class, 'store'])
    /*
     * Keyed by the token, not the IP: hooks behind one CI provider share an
     * address, so an IP limit would have one team's runaway job throttle
     * another's. See config/snack.php for the number.
     */
    ->middleware('throttle:webhooks')
    ->where('token', '[A-Za-z0-9]+')
    ->name('webhooks.deliver');
