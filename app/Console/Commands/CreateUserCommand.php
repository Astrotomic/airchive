<?php

namespace App\Console\Commands;

use App\Actions\Auth\VerifyAndEnableTwoFactorAuthentication;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create {email} {--name=}';

    protected $description = 'Create a user with TOTP MFA and print a passkey enrollment URL';

    public function handle(
        TwoFactorAuthenticationProvider $provider,
    ): int {
        $email = Str::lower($this->argument('email'));
        $name = $this->option('name') ?: Str::before($email, '@');

        $user = User::query()->firstOrCreate([
            'email' => $email,
        ], [
            'name' => $name,
        ]);

        $secret = $provider->generateSecretKey();

        $this->info('User created.');
        $this->line("Email: {$user->email}");
        $this->line("TOTP secret: {$secret}");
        $this->newLine();
        $this->comment('Add this secret to your authenticator app, then enter a code to confirm.');

        $code = $this->ask('Authentication code');

        try {
            $recoveryCodes = VerifyAndEnableTwoFactorAuthentication::make()
                ->execute($user, $secret, (string) $code);
        } catch (ValidationException $exception) {
            $user->delete();
            $this->error('The authentication code was invalid. The user was not created.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Two-factor authentication enabled.');
        $this->line('Recovery codes (store these securely):');
        foreach ($recoveryCodes as $recoveryCode) {
            $this->line("  - {$recoveryCode}");
        }

        $url = URL::temporarySignedRoute('enroll.show', now()->addMinutes(15), [
            'user' => $user,
        ]);

        $this->newLine();
        $this->info('Passkey enrollment URL (valid for 15 minutes):');
        $this->line($url);

        return self::SUCCESS;
    }
}
