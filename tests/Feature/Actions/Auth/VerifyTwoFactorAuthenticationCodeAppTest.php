<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\Auth\VerifyTwoFactorAuthenticationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class VerifyTwoFactorAuthenticationCodeAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_verifies_a_code_against_the_users_decrypted_secret(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        ]);

        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('secret', '123456')
                ->andReturnTrue();
        });

        Assert::assertTrue(
            VerifyTwoFactorAuthenticationCode::make()->execute($user, '123456'),
        );
    }

    public function test_it_rejects_a_code_when_the_user_has_no_two_factor_secret(): void
    {
        $user = User::factory()->create();

        $provider = $this->mock(TwoFactorAuthenticationProvider::class);
        $provider->shouldNotReceive('verify');

        Assert::assertFalse(
            VerifyTwoFactorAuthenticationCode::make()->execute($user, '123456'),
        );
    }

    public function test_it_returns_the_provider_result_for_an_invalid_code(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        ]);

        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('secret', 'invalid')
                ->andReturnFalse();
        });

        Assert::assertFalse(
            VerifyTwoFactorAuthenticationCode::make()->execute($user, 'invalid'),
        );
    }
}
