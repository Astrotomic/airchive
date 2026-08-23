<?php

namespace Tests\Integration\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AppTestCase;

class PasskeyRoutesAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_guest_can_request_passkey_login_options(): void
    {
        $this->getJson('/passkeys/login/options')
            ->assertOk()
            ->assertJsonStructure(['options']);
    }

    public function test_authenticated_user_can_request_passkey_registration_options(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/user/passkeys/options')
            ->assertOk()
            ->assertJsonStructure(['options']);
    }
}
