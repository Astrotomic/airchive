<?php

namespace Tests\Feature\Actions\Conversations;

use App\Actions\Conversations\ExportConversations;
use App\Enums\ExportFormat;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ExportConversationsAppTest extends AppTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $destinations = [];

    protected function tearDown(): void
    {
        foreach ($this->destinations as $destination) {
            File::deleteDirectory($destination);
        }

        parent::tearDown();
    }

    public function test_it_can_export_neither_chats_nor_files(): void
    {
        $destination = $this->destination();

        $result = ExportConversations::make()->execute(
            collect(),
            ExportFormat::Markdown,
            $destination,
            includeChats: false,
            includeFiles: false,
        );

        Assert::assertSame(0, $result->chatCount);
        Assert::assertSame(0, $result->fileCount);
        Assert::assertSame([], $result->unavailableFiles);
        Assert::assertDirectoryExists($destination);
    }

    public function test_it_uses_safe_fallback_chat_and_attachment_names(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $conversation = $this->conversation($user, null);
        Storage::put('attachments/fallback.txt', 'fallback');
        Storage::put('attachments/emoji.bin', 'emoji');
        $fallback = $this->attachment($user, $conversation, [
            'filename' => ' ',
            'storage_path' => 'attachments/fallback.txt',
        ]);
        $sanitized = $this->attachment($user, $conversation, [
            'filename' => '🔥',
            'storage_path' => 'attachments/emoji.bin',
        ]);
        $missing = $this->attachment($user, $conversation, [
            'filename' => null,
            'storage_path' => null,
        ]);
        $destination = $this->destination();

        $result = ExportConversations::make()->execute(
            collect([$conversation]),
            ExportFormat::Json,
            $destination,
        );

        Assert::assertSame(1, $result->chatCount);
        Assert::assertSame(2, $result->fileCount);
        Assert::assertSame(['attachment-'.$missing->id], $result->unavailableFiles);
        Assert::assertFileExists("{$destination}/chats/{$conversation->id}-conversation.json");
        Assert::assertFileExists("{$destination}/files/{$conversation->id}-conversation/{$fallback->id}-fallback.txt");
        Assert::assertFileExists("{$destination}/files/{$conversation->id}-conversation/{$sanitized->id}-attachment");
    }

    public function test_it_limits_conversation_and_attachment_filenames(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $conversation = $this->conversation($user, str_repeat('Long title ', 20));
        Storage::put('attachments/long.txt', 'long');
        $attachment = $this->attachment($user, $conversation, [
            'filename' => str_repeat('a', 200).'.txt',
            'storage_path' => 'attachments/long.txt',
        ]);
        $destination = $this->destination();

        ExportConversations::make()->execute(
            collect([$conversation]),
            ExportFormat::Markdown,
            $destination,
            includeChats: false,
        );

        $directories = File::directories($destination.'/files');
        $files = File::files($directories[0]);
        $directoryName = basename($directories[0]);
        $filename = basename($files[0]);

        Assert::assertSame($conversation->id.'-', substr($directoryName, 0, strlen((string) $conversation->id) + 1));
        Assert::assertSame(80, strlen(substr($directoryName, strlen((string) $conversation->id) + 1)));
        Assert::assertSame($attachment->id.'-', substr($filename, 0, strlen((string) $attachment->id) + 1));
        Assert::assertSame(180, strlen(substr($filename, strlen((string) $attachment->id) + 1)));
    }

    private function conversation(User $user, ?string $title): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'chatgpt',
            'source_conversation_id' => fake()->uuid(),
            'title' => $title,
            'metadata' => [],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function attachment(User $user, Conversation $conversation, array $attributes): Attachment
    {
        return Attachment::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'source_platform' => 'chatgpt',
            'attachment_type' => 'file',
            'source_ref' => [],
            ...$attributes,
        ]);
    }

    private function destination(): string
    {
        $destination = sys_get_temp_dir().'/airchive_export_action_'.bin2hex(random_bytes(8));
        $this->destinations[] = $destination;

        return $destination;
    }
}
