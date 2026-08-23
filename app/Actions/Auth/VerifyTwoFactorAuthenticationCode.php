<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class VerifyTwoFactorAuthenticationCode extends Action
{
    public function __construct(
        private TwoFactorAuthenticationProvider $provider,
    ) {}

    public function execute(User $user, string $code): bool
    {
        if (empty($user->two_factor_secret)) {
            return false;
        }

        return $this->provider->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $code,
        );
    }
}
