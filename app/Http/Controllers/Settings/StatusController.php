<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StatusUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class StatusController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StatusUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->statusAttributes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->user()->status_message === null
                ? __('Status cleared.')
                : __('Status updated.'),
        ]);

        return back();
    }
}
