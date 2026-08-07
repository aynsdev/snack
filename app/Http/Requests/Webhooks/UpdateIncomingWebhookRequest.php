<?php

namespace App\Http\Requests\Webhooks;

use App\Models\IncomingWebhook;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Editing a hook's name and appearance.
 *
 * There is no `channel_id` rule, and that is the point: the destination is fixed
 * at creation, so a request carrying one is simply ignored rather than honoured.
 * See {@see App\Actions\Webhooks\UpdateIncomingWebhook} for why re-aiming a live
 * URL is not an edit.
 */
class UpdateIncomingWebhookRequest extends FormRequest
{
    use ValidatesWebhookIdentity;

    /**
     * Returns the policy's own Response rather than a bool, so a non-member's
     * `denyAsNotFound()` stays a 404 instead of collapsing into a 403 that
     * confirms the hook exists.
     */
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->webhook());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->identityRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->identityMessages();
    }

    public function webhook(): IncomingWebhook
    {
        $webhook = $this->route('webhook');

        abort_unless($webhook instanceof IncomingWebhook, 404);

        return $webhook;
    }
}
