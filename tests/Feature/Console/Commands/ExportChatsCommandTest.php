<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\BlockType;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ExportChatsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_source_uuid_is_preferred_over_search_matches_and_exports_attached_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $uuid = fake()->uuid();
        $exact = $this->conversation($user, $uuid, 'Exact conversation', 'The selected content.');
        $searchMatch = $this->conversation($user, fake()->uuid(), 'Search match', "This mentions {$uuid}.");
        Storage::put('attachments/exact.txt', 'exact attachment');
        $attachment = $this->attachment($user, $exact, [
            'filename' => 'exact.txt',
            'storage_path' => 'attachments/exact.txt',
        ]);
        $destination = $this->temporaryDestination();

        try {
            $this->artisan('archive:export', [
                'chat' => $uuid,
                '--user' => $user->email,
                '--format' => 'json',
                '--output' => $destination,
            ])
                ->expectsOutputToContain("Resolved exact chat #{$exact->id}")
                ->expectsOutputToContain('Chats exported: 1')
                ->expectsOutputToContain('Files exported: 1')
                ->assertSuccessful();

            $chatPath = "{$destination}/chats/{$exact->id}-exact-conversation.json";
            $filePath = "{$destination}/files/{$exact->id}-exact-conversation/{$attachment->id}-exact.txt";

            Assert::assertFileExists($chatPath);
            Assert::assertFileExists($filePath);
            Assert::assertStringContainsString('The selected content.', File::get($chatPath));
            Assert::assertSame('exact attachment', File::get($filePath));
            Assert::assertFileDoesNotExist("{$destination}/chats/{$searchMatch->id}-search-match.json");
        } finally {
            File::deleteDirectory($destination);
        }
    }

    public function test_search_exports_all_matching_chats_without_files_in_selected_format(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $first = $this->conversation($user, fake()->uuid(), 'Launch plan', 'First rocket checklist.');
        $second = $this->conversation($user, fake()->uuid(), 'Retrospective', 'The rocket launched.');
        $unmatched = $this->conversation($user, fake()->uuid(), 'Groceries', 'Buy apples.');
        Storage::put('attachments/launch.txt', 'launch file');
        $this->attachment($user, $first, [
            'filename' => 'launch.txt',
            'storage_path' => 'attachments/launch.txt',
        ]);
        $destination = $this->temporaryDestination();

        try {
            $this->artisan('archive:export', [
                'chat' => 'rocket',
                '--user' => (string) $user->id,
                '--chats-only' => true,
                '--format' => 'html',
                '--output' => $destination,
            ])
                ->expectsOutputToContain("Found 2 chats matching 'rocket'.")
                ->expectsOutputToContain('Chats exported: 2')
                ->expectsOutputToContain('Files exported: 0')
                ->assertSuccessful();

            Assert::assertFileExists("{$destination}/chats/{$first->id}-launch-plan.html");
            Assert::assertFileExists("{$destination}/chats/{$second->id}-retrospective.html");
            Assert::assertFileDoesNotExist("{$destination}/chats/{$unmatched->id}-groceries.html");
            Assert::assertDirectoryDoesNotExist("{$destination}/files");
        } finally {
            File::deleteDirectory($destination);
        }
    }

    public function test_files_only_exports_direct_attachments_and_sanitizes_source_filenames(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $conversation = $this->conversation($user, fake()->uuid(), 'Files test', 'A chat with files.');
        $otherConversation = $this->conversation($user, fake()->uuid(), 'Other', 'Other files.');
        Storage::put('attachments/direct.txt', 'direct');
        Storage::put('attachments/other.txt', 'other');
        Storage::put('attachments/library.txt', 'library');
        $direct = $this->attachment($user, $conversation, [
            'filename' => '../unsafe/direct.txt',
            'storage_path' => 'attachments/direct.txt',
        ]);
        $this->attachment($user, $otherConversation, [
            'filename' => 'other.txt',
            'storage_path' => 'attachments/other.txt',
        ]);
        $this->attachment($user, null, [
            'filename' => 'library.txt',
            'storage_path' => 'attachments/library.txt',
        ]);
        $destination = $this->temporaryDestination();

        try {
            $this->artisan('archive:export', [
                'chat' => (string) $conversation->id,
                '--user' => $user->email,
                '--files-only' => true,
                '--output' => $destination,
            ])
                ->expectsOutputToContain('Chats exported: 0')
                ->expectsOutputToContain('Files exported: 1')
                ->assertSuccessful();

            $filePath = "{$destination}/files/{$conversation->id}-files-test/{$direct->id}-direct.txt";

            Assert::assertFileExists($filePath);
            Assert::assertSame('direct', File::get($filePath));
            Assert::assertDirectoryDoesNotExist("{$destination}/chats");
            Assert::assertCount(1, File::allFiles($destination));
        } finally {
            File::deleteDirectory($destination);
        }
    }

    public function test_rejects_conflicting_modes_and_unsupported_formats(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user, fake()->uuid(), 'Options', 'Options test.');

        $this->artisan('archive:export', [
            'chat' => (string) $conversation->id,
            '--chats-only' => true,
            '--files-only' => true,
        ])
            ->expectsOutputToContain('cannot be used together')
            ->assertFailed();

        $this->artisan('archive:export', [
            'chat' => (string) $conversation->id,
            '--format' => 'xml',
        ])
            ->expectsOutputToContain('Unsupported format')
            ->assertFailed();
    }

    public function test_reports_unavailable_attachment_files_without_failing_the_chat_export(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $conversation = $this->conversation($user, fake()->uuid(), 'Missing file', 'Still export the chat.');
        $this->attachment($user, $conversation, [
            'filename' => 'missing.pdf',
            'storage_path' => 'attachments/missing.pdf',
        ]);
        $destination = $this->temporaryDestination();

        try {
            $this->artisan('archive:export', [
                'chat' => (string) $conversation->id,
                '--output' => $destination,
            ])
                ->expectsOutputToContain('Chats exported: 1')
                ->expectsOutputToContain('Files exported: 0')
                ->expectsOutputToContain('missing.pdf')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($destination);
        }
    }

    private function conversation(
        User $user,
        string $sourceId,
        string $title,
        string $body,
    ): Conversation {
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => $sourceId,
            'title' => $title,
            'metadata' => [],
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => fake()->uuid(),
            'role' => 'assistant',
            'created_at' => now(),
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => [],
        ]);

        ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::Text,
            'text_content' => $body,
        ]);

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attachment(User $user, ?Conversation $conversation, array $attributes): Attachment
    {
        return Attachment::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'message_id' => $conversation?->messages()->value('id'),
            'source_platform' => SourcePlatform::ChatGpt->value,
            'source_attachment_id' => 'file-'.fake()->uuid(),
            'attachment_type' => 'file',
            'source_ref' => [],
            ...$attributes,
        ]);
    }

    private function temporaryDestination(): string
    {
        return sys_get_temp_dir().'/airchive_export_'.bin2hex(random_bytes(8));
    }
}
