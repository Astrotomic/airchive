<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaIsVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->session()->get('auth.two_factor_verified')) {
            return $next($request);
        }

        if ($request->routeIs(
            'two-factor.*',
            'login',
            'logout',
            'enroll.*',
            'passkey.login',
        )) {
            return $next($request);
        }

        $request->session()->put('login.id', $user->getKey());
        Auth::logout();

        return redirect()->route('two-factor.login');
    }
}
