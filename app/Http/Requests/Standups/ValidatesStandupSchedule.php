<?php

namespace App\Http\Requests\Standups;

/**
 * The rules a standup's questions and schedule share between creating one and
 * editing one.
 *
 * Shared as a trait rather than by having the update request extend the store
 * request: the two differ in whether the channel may be named and in whether
 * every field is required, and inheritance would make one of those the default
 * and the other an override that is easy to forget.
 */
trait ValidatesStandupSchedule
{
    /**
     * @return array<string, mixed>
     */
    protected function scheduleRules(): array
    {
        return [
            /*
             * At least one question, capped — see `snack.standups.max_questions`
             * for why a standup with fifteen questions is a form nobody fills
             * in. Blank entries are stripped in prepareForValidation() rather
             * than rejected, because an empty row in a repeater is what an
             * abandoned "add another" looks like, not an error.
             */
            'questions' => ['required', 'array', 'min:1', 'max:'.config('snack.standups.max_questions', 10)],
            'questions.*' => ['required', 'string', 'min:1', 'max:'.config('snack.standups.max_question_length', 200)],

            /*
             * ISO weekday numbers, 1 = Monday. `distinct` because a duplicate
             * would not ask twice — the run is unique per date — but it would
             * make "Monday, Monday and Friday" the label the admin is shown.
             */
            'days_of_week' => ['required', 'array', 'min:1', 'max:7'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],

            // HH:MM, in the timezone below. Seconds are not offered: nobody
            // schedules a standup for 09:30:17.
            'ask_at' => ['required', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],

            /*
             * Validated against the machine's own timezone database rather than
             * a list of our own. A list would be a second, staler copy of a
             * thing the platform already knows, and the first political change
             * to a zone would make the two disagree.
             */
            'timezone' => ['required', 'string', 'max:64', 'timezone'],

            /*
             * Null means "do not chase", which is a real answer and not a
             * missing one. Bounded below by a minute so a reminder cannot be
             * scheduled for the same instant as the question.
             */
            'reminder_after_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],

            // The write-up window. At least a minute, at most a day: a standup
            // that stays open longer than that is not a standup.
            'closes_after_minutes' => ['required', 'integer', 'min:1', 'max:1440'],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function scheduleMessages(): array
    {
        return [
            'questions.required' => 'A standup needs at least one question.',
            'questions.max' => 'A standup can ask at most :max questions.',
            'days_of_week.required' => 'Pick at least one day for the standup to run.',
            'ask_at.regex' => 'Give the time as HH:MM, for example 09:30.',
            'timezone.timezone' => 'That is not a timezone this server recognises.',
        ];
    }

    /**
     * Drop the blank rows a repeater leaves behind.
     *
     * The alternative — refusing the submission — turns "I clicked Add question
     * and changed my mind" into a validation error pointing at an empty box,
     * which is a worse experience than the one thing this costs: an admin who
     * genuinely submitted nothing but blanks still gets the "needs at least one
     * question" message, because stripping leaves the array empty.
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('questions'))) {
            return;
        }

        $this->merge([
            'questions' => collect($this->input('questions'))
                ->filter(fn (mixed $question): bool => is_string($question) && trim($question) !== '')
                ->map(fn (string $question): string => trim($question))
                ->values()
                ->all(),
        ]);
    }

    /*
     * The accessors below exist so the controller can hand the actions a typed
     * array shape.
     *
     * `validated()` answers `mixed` — the rules have already established what
     * these are, but that knowledge lives in a rule string and does not reach
     * the type system. Narrowing here, once, is what lets the actions declare a
     * real parameter shape instead of `array<string, mixed>`, which is the
     * difference between a renamed key failing loudly and arriving as a default.
     */

    /**
     * @return array<int, string>
     */
    public function questions(): array
    {
        $questions = $this->validated('questions');

        if (! is_array($questions)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $question): string => is_string($question) ? $question : '',
            $questions,
        ));
    }

    /**
     * ISO weekday numbers. They come from a row of buttons, so they may be
     * strings on the wire even though the rule reads them as integers.
     *
     * @return array<int, int>
     */
    public function weekdays(): array
    {
        $days = $this->validated('days_of_week');

        if (! is_array($days)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (mixed $day): int => (int) $day,
            $days,
        )));
    }

    /**
     * Null is a value here — "do not chase" — rather than an absent field, which
     * is why this is not `$this->integer(...)`: that reads an empty box as a
     * reminder zero minutes after the question.
     */
    public function reminderAfterMinutes(): ?int
    {
        $minutes = $this->validated('reminder_after_minutes');

        return is_numeric($minutes) ? (int) $minutes : null;
    }
}
