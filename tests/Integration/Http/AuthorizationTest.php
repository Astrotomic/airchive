<?php

namespace Tests\Integration\Http;

use App\Actions\Auth\VerifyTwoFactorAuthenticationCode;
use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\User;
use Astrotomic\PhpunitAssertions\EmailAssertions;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthorizationTest extends TestCase
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
}
