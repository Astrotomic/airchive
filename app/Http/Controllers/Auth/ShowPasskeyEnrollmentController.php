<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\View\View;

class ShowPasskeyEnrollmentController
{
    public function __invoke(User $user): View
    {
        return view('auth.enroll', [
            'user' => $user,
        ]);
    }
}
