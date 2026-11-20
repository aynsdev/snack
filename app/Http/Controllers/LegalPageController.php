<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * The public terms, privacy policy and account deletion pages.
 *
 * The App Store and Play Store listings link here, so these are reachable
 * without signing in. The operator they name comes from `snack.legal`, because
 * every installation is run by someone different.
 */
class LegalPageController extends Controller
{
    public function terms(): Response
    {
        return $this->render('legal/terms');
    }

    public function privacy(): Response
    {
        return $this->render('legal/privacy');
    }

    public function deleteAccount(): Response
    {
        return $this->render('legal/delete-account');
    }

    private function render(string $component): Response
    {
        return Inertia::render($component, [
            'legal' => [
                'company' => (string) config('snack.legal.company'),
                'country' => (string) config('snack.legal.country'),
                'contactEmail' => (string) config('snack.legal.contact_email'),
            ],
        ]);
    }
}
