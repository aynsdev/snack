<?php

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member's own notification defaults for one workspace.
 *
 * No authorization check beyond the route's: these are the requester's own
 * settings on their own membership row, resolved from
 * {@see App\Support\WorkspaceContext} rather than from anything the request
 * names — so there is no identifier here that could point at somebody else.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'default_level' => ['required', Rule::enum(NotificationLevel::class)],
            'email_enabled' => ['required', 'boolean'],
            'push_enabled' => ['sometimes', 'boolean'],
            'digest_enabled' => ['required', 'boolean'],

            /*
             * Quiet hours are all-or-nothing: a start with no end is not a
             * window, and storing half of one would leave
             * WorkspaceMember::doNotDisturbSchedule() reading a shape it has to
             * guard against on every call.
             */
            'do_not_disturb' => ['nullable', 'array'],
            'do_not_disturb.start' => ['required_with:do_not_disturb', 'date_format:H:i'],
            'do_not_disturb.end' => ['required_with:do_not_disturb', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'do_not_disturb.start.date_format' => 'Quiet hours must be a time like 22:00.',
            'do_not_disturb.end.date_format' => 'Quiet hours must be a time like 07:00.',
        ];
    }

    public function defaultLevel(): NotificationLevel
    {
        return NotificationLevel::from((string) $this->input('default_level'));
    }

    /**
     * The quiet-hours window, or null when the member has turned it off.
     *
     * A window whose start equals its end is treated as "off" rather than as a
     * zero-length one: it is what a form leaves behind when somebody clears the
     * fields, and reading it literally would silence nothing while looking set.
     *
     * @return array{start: string, end: string}|null
     */
    public function doNotDisturb(): ?array
    {
        $schedule = $this->input('do_not_disturb');

        if (! is_array($schedule)) {
            return null;
        }

        $start = (string) ($schedule['start'] ?? '');
        $end = (string) ($schedule['end'] ?? '');

        return $start === '' || $start === $end
            ? null
            : ['start' => $start, 'end' => $end];
    }

    /**
     * Null when the form did not mention push — see UpdateNotificationPreferences.
     */
    public function pushEnabled(): ?bool
    {
        return $this->has('push_enabled') ? $this->boolean('push_enabled') : null;
    }
}
