<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Snack controllers authorize explicitly with `$this->authorize()` rather
     * than relying on implicit model binding, because most abilities are
     * evaluated against the current workspace as well as the model.
     */
    use AuthorizesRequests;
}
