<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class DevicePairingController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $url = URL::temporarySignedRoute('enroll.show', now()->addMinutes(15), [
            'user' => $request->user(),
        ]);

        return redirect()
            ->back()
            ->with('device_pairing_url', $url);
    }
}
