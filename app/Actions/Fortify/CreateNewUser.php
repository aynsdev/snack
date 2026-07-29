<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Services\RegistrationGate;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly RegistrationGate $gate,
        private readonly SlugGenerator $slugs,
    ) {}

    /**
     * Validate and create a newly registered user.
     *
     * Snack is invitation-first, so this is the server-side enforcement point:
     * even if someone posts directly to the registration endpoint, they cannot
     * create an account without either public registration being enabled or a
     * live invitation in their session.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        if (! $this->gate->isOpen()) {
            throw ValidationException::withMessages([
                'email' => 'Registration is by invitation only.',
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // An invited visitor must register with the address the invitation was
        // sent to; otherwise a forwarded link would onboard the wrong person.
        $requiredEmail = $this->gate->requiredEmail();

        if ($requiredEmail !== null && mb_strtolower($requiredEmail) !== mb_strtolower($input['email'])) {
            throw ValidationException::withMessages([
                'email' => "This invitation was sent to {$requiredEmail}.",
            ]);
        }

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            // Derived so registration stays a short form; members can pick a
            // different handle later from their profile settings.
            'username' => $this->slugs->forUsername($input['name']),
            'password' => $input['password'],
            'timezone' => config('app.timezone', 'UTC'),
        ]);
    }
}
