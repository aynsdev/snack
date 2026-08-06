<?php

namespace App\Http\Requests\Standups;

use App\Models\StandupResponse;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * One person answering their own standup.
 *
 * The answers arrive as a positional list matching the questions the *prompt*
 * carried, which is deliberately not the same thing as the standup's current
 * questions — see {@see App\Actions\Standups\SubmitStandupResponse}. Nothing is
 * validated about their number here for that reason: the client's list can only
 * ever be as fresh as the page it was rendered on, and the action aligns and
 * truncates against the snapshot rather than rejecting a submission for a
 * mismatch the person answering could not have known about.
 */
class StoreStandupResponseRequest extends FormRequest
{
    /**
     * Returns the gate's own Response rather than a bool.
     *
     * The distinction the policy draws here is not decoration: answering
     * somebody else's standup is refused as *not found*, so that a probed id
     * does not confirm whose morning it belongs to. Collapsing that to a
     * boolean would turn every one of those into a 403 — which is itself the
     * answer to the question the prober asked.
     */
    public function authorize(): Response
    {
        $response = $this->route('response');

        if (! $response instanceof StandupResponse) {
            return Response::denyAsNotFound();
        }

        return Gate::inspect('respond', $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['array', 'max:'.config('snack.standups.max_questions', 10)],

            /*
             * Nullable rather than required: leaving one question blank is a
             * normal thing to do, and the pairs with nothing in them are dropped
             * before the summary is written.
             */
            'answers.*' => ['nullable', 'string', 'max:'.config('snack.standups.max_answer_length', 2000)],

            // "Nothing from me today" — an answer, not the absence of one.
            'skipped' => ['boolean'],
        ];
    }

    /**
     * The answers, in the order the questions were asked.
     *
     * @return array<int, string>
     */
    public function answers(): array
    {
        $answers = $this->input('answers', []);

        if (! is_array($answers)) {
            return [];
        }

        return collect($answers)
            // A blank box arrives as null rather than as "", and the action
            // drops empty answers anyway — this only has to make the list a list
            // of strings so the positions still line up with the questions.
            ->map(fn (mixed $answer): string => is_string($answer) ? $answer : '')
            ->values()
            ->all();
    }
}
