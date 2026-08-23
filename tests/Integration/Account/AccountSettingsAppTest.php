<?php

namespace Tests\Integration\Account;

use App\Models\Session;
use App\Models\User;
use App\ValueObjects\IpInfo;
use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Astrotomic\PhpunitAssertions\CountryAssertions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Spatie\Emoji\Emoji;
use Tests\AppTestCase;
use UAParser\Result\Client;

class AccountSettingsAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_account_page_lists_passkeys_and_sessions(): void
    {
        config(['session.driver' => 'database']);
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'countryCode' => 'US',
                'region' => 'CA',
                'city' => 'Mountain View',
                'zip' => '94043',
            ]),
        ]);

        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $user->passkeys()->create([
            'name' => 'Test Laptop',
            'credential_id' => 'cred-123',
            'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
            'last_used_at' => now()->subHour(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'current-session-id',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:150.0) Gecko/20100101 Firefox/150.0',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->timestamp,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->get(route('account.settings'));

        $response
            ->assertOk()
            ->assertSee('Test Laptop')
            ->assertSee('Firefox 150.0')
            ->assertSee('Mac OS X 10.15')
            ->assertSee('127.0.0.1')
            ->assertSee(Emoji::countryFlag('US'))
            ->assertSee('Mountain View, CA 94043')
            ->assertSee('Recovery codes');
    }

    public function test_user_can_delete_passkey_when_more_than_one_exists(): void
    {
        $user = User::factory()->create();

        $keep = $user->passkeys()->create([
            'name' => 'Keep',
            'credential_id' => 'cred-keep',
            'credential' => [],
        ]);

        $remove = $user->passkeys()->create([
            'name' => 'Remove me',
            'credential_id' => 'cred-remove',
            'credential' => [],
        ]);

        Livewire::actingAs($user)
            ->test('account-settings')
            ->call('deletePasskey', $remove->id)
            ->assertSet('securityNotice', 'Passkey "Remove me" was removed.');

        $this->assertDatabaseMissing('passkeys', ['id' => $remove->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $keep->id]);
    }

    public function test_user_cannot_delete_last_passkey(): void
    {
        $user = User::factory()->create();

        $passkey = $user->passkeys()->create([
            'name' => 'Only one',
            'credential_id' => 'cred-only',
            'credential' => [],
        ]);

        Livewire::actingAs($user)
            ->test('account-settings')
            ->call('deletePasskey', $passkey->id)
            ->assertSet('securityError', 'You must keep at least one passkey registered to sign in.');

        $this->assertDatabaseHas('passkeys', ['id' => $passkey->id]);
    }

    public function test_session_relation_returns_session_models(): void
    {
        $user = User::factory()->create();

        $this->insertSession($user, 'older-session', now()->subHour()->timestamp);
        $this->insertSession($user, 'newer-session', now()->timestamp);

        $sessions = $user->sessions()->latest('last_activity')->get();
        $modelKeys = $sessions->modelKeys();

        Assert::assertContainsOnlyInstancesOf(Session::class, $sessions);
        ArrayAssertions::assertIndexed($modelKeys);
        Assert::assertSame(['newer-session', 'older-session'], $modelKeys);
        Assert::assertInstanceOf(CarbonImmutable::class, $sessions->first()->last_activity);
    }

    public function test_session_knows_whether_it_is_current(): void
    {
        $currentSession = new Session;
        $currentSession->setAttribute('id', session()->getId());

        $otherSession = new Session;
        $otherSession->setAttribute('id', 'other-session');

        Assert::assertTrue($currentSession->isCurrent());
        Assert::assertFalse($otherSession->isCurrent());
    }

    public function test_parsed_user_agent_is_cached_on_the_session_model(): void
    {
        $session = new Session;
        $session->setAttribute(
            'user_agent',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:150.0) Gecko/20100101 Firefox/150.0',
        );

        $parsedUserAgent = $session->parsed_user_agent;

        Assert::assertInstanceOf(Client::class, $parsedUserAgent);
        Assert::assertSame('Firefox 150.0', $parsedUserAgent->ua->toString());
        Assert::assertSame('Mac OS X 10.15', $parsedUserAgent->os->toString());
        Assert::assertSame($parsedUserAgent, $session->parsed_user_agent);
    }

    public function test_ip_info_is_cached_on_the_session_model(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'countryCode' => 'DE',
                'region' => 'BE',
                'city' => 'Berlin',
                'zip' => '10115',
            ]),
        ]);

        $session = new Session;
        $session->setAttribute('ip_address', '8.8.8.8');

        $ipInfo = $session->ip_info;

        Assert::assertInstanceOf(IpInfo::class, $ipInfo);
        CountryAssertions::assertAlpha2($ipInfo->countryCode);
        Assert::assertSame('DE', $ipInfo->countryCode);
        Assert::assertSame('BE', $ipInfo->regionCode);
        Assert::assertSame('Berlin', $ipInfo->city);
        Assert::assertSame('10115', $ipInfo->zip);
        Assert::assertSame($ipInfo, $session->ip_info);
        Http::assertSentCount(1);
    }

    public function test_session_revocation_is_scoped_to_the_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->insertSession($user, 'owned-session');
        $this->insertSession($otherUser, 'foreign-session');

        Assert::assertTrue($user->sessions()->revoke('owned-session'));
        Assert::assertFalse($user->sessions()->revoke('foreign-session'));
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }

    public function test_user_can_revoke_all_sessions_except_the_current_one(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->insertSession($user, 'current-session');
        $this->insertSession($user, 'other-session');
        $this->insertSession($otherUser, 'foreign-session');

        Assert::assertSame(1, $user->sessions()->revokeOthers('current-session'));
        $this->assertDatabaseHas('sessions', ['id' => 'current-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }

    private function insertSession(User $user, string $id, ?int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => null,
            'payload' => base64_encode(serialize([])),
            'last_activity' => $lastActivity ?? now()->timestamp,
        ]);
    }
}
