<?php

namespace Tests\Feature\Console\Commands;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class CreateUserCommandAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_normalized_user_and_prints_recovery_information(): void
    {
        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $provider): void {
            $provider->shouldReceive('generateSecretKey')->once()->andReturn('totp-secret');
            $provider->shouldReceive('verify')->once()->with('totp-secret', '123456')->andReturnTrue();
        });

        $this->artisan('user:create', [
            'email' => 'TEST@Example.COM',
            '--name' => 'Test User',
        ])
            ->expectsQuestion('Authentication code', '123456')
            ->expectsOutputToContain('User created.')
            ->expectsOutputToContain('Email: test@example.com')
            ->expectsOutputToContain('TOTP secret: totp-secret')
            ->expectsOutputToContain('Recovery codes (store these securely):')
            ->expectsOutputToContain('Passkey enrollment URL')
            ->assertSuccessful();

        $user = User::query()->sole();
        Assert::assertSame('test@example.com', $user->email);
        Assert::assertSame('Test User', $user->name);
        Assert::assertSame('totp-secret', Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
        Assert::assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_it_derives_the_name_from_email_when_no_name_is_given(): void
    {
        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $provider): void {
            $provider->shouldReceive('generateSecretKey')->once()->andReturn('secret');
            $provider->shouldReceive('verify')->once()->with('secret', '123456')->andReturnTrue();
        });

        $this->artisan('user:create', ['email' => 'tom@example.com'])
            ->expectsQuestion('Authentication code', '123456')
            ->assertSuccessful();

        Assert::assertSame('tom', User::query()->sole()->name);
    }

    public function test_it_removes_a_new_user_when_totp_confirmation_fails(): void
    {
        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $provider): void {
            $provider->shouldReceive('generateSecretKey')->once()->andReturn('secret');
            $provider->shouldReceive('verify')->once()->with('secret', 'invalid')->andReturnFalse();
        });

        $this->artisan('user:create', ['email' => 'failed@example.com'])
            ->expectsQuestion('Authentication code', 'invalid')
            ->expectsOutputToContain('The authentication code was invalid. The user was not created.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'failed@example.com']);
    }
}
