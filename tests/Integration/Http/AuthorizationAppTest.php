<?php

namespace Tests\Integration\Http;

use App\Actions\Auth\VerifyTwoFactorAuthenticationCode;
use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\User;
use Astrotomic\PhpunitAssertions\EmailAssertions;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class AuthorizationAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_user_cannot_view_another_users_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $conversation = Conversation::query()->create([
            'user_id' => $owner->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'conv-1',
            'title' => 'Private',
            'metadata' => [],
        ]);

        $this->actingAs($other)
            ->get(route('conversations.show', $conversation))
            ->assertNotFound();
    }

    public function test_user_can_export_own_conversation(): void
    {
        $user = User::factory()->create();

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'conv-export',
            'title' => 'Export me',
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->get(route('conversations.export', [$conversation, 'format' => 'md']))
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_conversation_export_validates_format_and_uses_a_fallback_filename(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::Cursor,
            'source_conversation_id' => 'fallback-export',
            'title' => null,
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->get(route('conversations.export', [$conversation, 'format' => 'html']))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertDownload('conversation.html');

        $this->actingAs($user)
            ->get(route('conversations.export', [$conversation, 'format' => 'xml']))
            ->assertNotFound();
    }

    public function test_signed_enrollment_url_uses_route_binding(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute('enroll.show', now()->addMinutes(15), [
            'user' => $user,
        ]);

        UrlAssertions::assertValidLoose($url);
        $this->get($url)
            ->assertOk()
            ->assertViewHas('user', fn (User $boundUser): bool => $boundUser->is($user));

        $this->get($url)->assertOk();
    }

    public function test_enrollment_verification_posts_to_the_signed_user_route(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute('enroll.show', now()->addMinutes(15), [
            'user' => $user,
        ]);

        UrlAssertions::assertValidLoose($url);
        EmailAssertions::assertValidStrict($user->email);
        VerifyTwoFactorAuthenticationCode::fake()->shouldReceive('execute')
            ->once()
            ->withArgs(fn (User $boundUser, string $code): bool => $boundUser->is($user) && $code === '123456')
            ->andReturnTrue();

        $this->post($url, [
            'email' => $user->email,
            'code' => '123456',
        ])->assertRedirect(route('enroll.register'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_enrollment_rejects_mismatched_email_and_invalid_code(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute('enroll.verify', now()->addMinutes(15), [
            'user' => $user,
        ]);
        VerifyTwoFactorAuthenticationCode::fake()->shouldReceive('execute')->once()->andReturnFalse();

        $this->from($url)->post($url, [
            'email' => 'other@example.com',
            'code' => '123456',
        ])->assertRedirect($url)->assertSessionHasErrors('email');

        $this->from($url)->post($url, [
            'email' => strtoupper($user->email),
            'code' => 'invalid',
        ])->assertRedirect($url)->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_device_pairing_creates_a_signed_enrollment_link(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->from(route('account.settings'))
            ->post(route('account.devices.pair'))
            ->assertRedirect(route('account.settings'))
            ->assertSessionHas('device_pairing_url');

        $url = Session::get('device_pairing_url');
        Assert::assertIsString($url);
        Assert::assertTrue(URL::hasValidSignature(
            Request::create($url),
        ));
        Assert::assertStringContainsString('/enroll/'.$user->id, $url);
    }

    public function test_mfa_middleware_logs_out_unverified_users_and_allows_users_without_mfa(): void
    {
        $withoutMfa = User::factory()->create();
        $this->actingAs($withoutMfa)
            ->get(route('conversations.index'))
            ->assertOk();

        $withMfa = User::factory()->create([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($withMfa)
            ->get(route('conversations.index'))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $withMfa->id);

        $this->assertGuest();
    }
}
