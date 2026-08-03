<?php

namespace App\Http\Requests\Presence;

use App\Enums\PresenceStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A tab reporting where its user's attention is.
 *
 * Only the two statuses a client is entitled to claim are accepted. Offline is
 * not one of them — that is the absence of a heartbeat, arrived at by the sweep
 * or by signing out — and neither is do-not-disturb, which is a deliberate
 * choice made in settings rather than something a timer may assert on
 * somebody's behalf.
 */
class HeartbeatRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'sometimes',
                Rule::enum(PresenceStatus::class)->only([
                    PresenceStatus::Online,
                    PresenceStatus::Away,
                ]),
            ],
        ];
    }

    /**
     * Defaults to Online: a request arrived, so somebody is plainly there, and
     * a client that forgets to say which it is should not be punished with a
     * validation error on a background ping.
     */
    public function status(): PresenceStatus
    {
        return PresenceStatus::tryFrom((string) $this->string('status'))
            ?? PresenceStatus::Online;
    }
}
