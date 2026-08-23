<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;

class VerifyAndEnableTwoFactorAuthentication extends Action
{
    public function __construct(
        private TwoFactorAuthenticationProvider $provider,
    ) {}

    /**
     * @return array<int, string>
     */
    public function execute(User $user, string $secret, string $code): array
    {
        if (! $this->provider->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ]);
        }

        $recoveryCodes = Collection::times(8, fn () => RecoveryCode::generate())->all();

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }
}
