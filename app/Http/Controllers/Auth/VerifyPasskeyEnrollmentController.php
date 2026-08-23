<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\VerifyTwoFactorAuthenticationCode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class VerifyPasskeyEnrollmentController
{
    public function __invoke(
        Request $request,
        User $user,
    ): RedirectResponse {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        if (strcasecmp($user->email, $validated['email']) !== 0) {
            throw ValidationException::withMessages([
                'email' => ['We could not match that email address to this enrollment link.'],
            ]);
        }

        if (! VerifyTwoFactorAuthenticationCode::make()->execute($user, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['The authentication code you entered is incorrect.'],
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('auth.two_factor_verified', true);

        return redirect()->route('enroll.register');
    }
}
