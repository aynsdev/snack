<?php

namespace App\Http\Requests\Webhooks;

use App\Models\IncomingWebhook;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Turning signing on or off, or replacing the secret.
 *
 * A named mode rather than a boolean, because there are three operations and
 * only two states — see {@see App\Actions\Webhooks\UpdateWebhookSigning} for why
 * rotating cannot be expressed as "enable it again".
 */
class UpdateWebhookSigningRequest extends FormRequest
{
    public const ENABLE = 'enable';

    public const DISABLE = 'disable';

    public const ROTATE = 'rotate';

    /**
     * Returns the policy's own Response so a non-member's `denyAsNotFound()`
     * stays a 404 rather than collapsing into a 403 that confirms the hook.
     */
    public function authorize(): Response
    {
        return Gate::inspect('updateSigning', $this->webhook());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([self::ENABLE, self::DISABLE, self::ROTATE])],
        ];
    }

    public function webhook(): IncomingWebhook
    {
        $webhook = $this->route('webhook');

        abort_unless($webhook instanceof IncomingWebhook, 404);

        return $webhook;
    }

    public function mode(): string
    {
        return $this->string('mode')->toString();
    }
}
