<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\Auth\VerifyAndEnableTwoFactorAuthentication;
use App\Models\User;
use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Astrotomic\PhpunitAssertions\StringLengthAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class VerifyAndEnableTwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_and_enables_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('secret', '123456')
                ->andReturnTrue();
        });

        $recoveryCodes = VerifyAndEnableTwoFactorAuthentication::make()
            ->execute($user, 'secret', '123456');

        $user->refresh();
        $storedRecoveryCodes = json_decode(
            Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes),
            true,
        );

        ArrayAssertions::assertIndexed($recoveryCodes);
        foreach ($recoveryCodes as $recoveryCode) {
            StringLengthAssertions::assertSame(21, $recoveryCode);
        }
        Assert::assertCount(8, $recoveryCodes);
        Assert::assertSame('secret', Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
        ArrayAssertions::assertIndexed($storedRecoveryCodes);
        Assert::assertSame($recoveryCodes, $storedRecoveryCodes);
        Assert::assertNotNull($user->two_factor_confirmed_at);
    }

    public function test_it_rejects_an_invalid_code_without_enabling_two_factor_authentication(): void
    {
        $user = User::factory()->create();

        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')
                ->once()
                ->with('secret', 'invalid')
                ->andReturnFalse();
        });

        try {
            VerifyAndEnableTwoFactorAuthentication::make()->execute($user, 'secret', 'invalid');
            Assert::fail('A validation exception was not thrown.');
        } catch (ValidationException $exception) {
            Assert::assertArrayHasKey('code', $exception->errors());
        }

        Assert::assertNull($user->fresh()->two_factor_secret);
    }
}
