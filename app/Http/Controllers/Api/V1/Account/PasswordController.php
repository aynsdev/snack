<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\Response;

class PasswordController extends Controller
{
    public function update(PasswordUpdateRequest $request): Response
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        return response()->noContent();
    }
}
