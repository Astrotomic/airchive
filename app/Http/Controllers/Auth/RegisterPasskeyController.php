<?php

namespace App\Http\Controllers\Auth;

use Illuminate\View\View;

class RegisterPasskeyController
{
    public function __invoke(): View
    {
        return view('auth.register-passkey');
    }
}
