<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\BlockType;
use App\Enums\SourcePlatform;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProjectSourceIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class BackfillProjectsCommandAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_rejects_an_unknown_user(): void
    {
        $this->artisan('archive:projects-backfill', ['--user' => 'missing@example.com'])
            ->expectsOutputToContain("No user found for 'missing@example.com'.")
            ->assertFailed();
    }

    public function test_it_can_scope_by_numeric_user_id_and_process_cursor_metadata(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $included = $this->conversation($user, SourcePlatform::Cursor, [
            'cursor_workspace' => 'workspace-included',
        ]);
        $excluded = $this->conversation($other, SourcePlatform::Cursor, [
            'cursor_workspace' => 'workspace-excluded',
        ]);

        $this->artisan('archive:projects-backfill', ['--user' => (string) $user->id])
            ->expectsOutputToContain('1 conversations scanned, 1 project assignments found')
            ->assertSuccessful();

        Assert::assertSame('workspace-included', $included->projects()->sole()->sourceIdentifiers()->sole()->source_identifier);
        Assert::assertCount(0, $excluded->projects()->get());
    }

    public function test_it_extracts_codex_repositories_from_messages_and_blocks(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user, SourcePlatform::Codex, ['repo_id' => 'metadata-repo']);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'message-1',
            'role' => 'user',
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => ['repo_id' => 'message-repo'],
        ]);
        ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::Other,
            'structured_content' => ['nested' => ['repo_id' => 'block-repo']],
        ]);

        $this->artisan('archive:projects-backfill')
            ->expectsOutputToContain('1 conversations scanned, 3 project assignments found')
            ->assertSuccessful();

        Assert::assertEqualsCanonicalizing(
            ['metadata-repo', 'message-repo', 'block-repo'],
            ProjectSourceIdentifier::query()->pluck('source_identifier')->all(),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function conversation(User $user, SourcePlatform $platform, array $metadata): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => $platform,
            'source_conversation_id' => fake()->uuid(),
            'title' => 'Conversation',
            'metadata' => $metadata,
        ]);
    }
}
