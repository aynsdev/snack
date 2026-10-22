<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StatusUpdateRequest;
use App\Http\Resources\Api\V1\AccountResource;

class StatusController extends Controller
{
    public function __invoke(StatusUpdateRequest $request): AccountResource
    {
        $request->user()->update($request->statusAttributes());

        return new AccountResource($request->user());
    }
}
