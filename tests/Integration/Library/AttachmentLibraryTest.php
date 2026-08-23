<?php

namespace Tests\Integration\Library;

use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class AttachmentLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_lists_only_the_users_files_with_chat_metadata(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $conversation = $this->conversation($user, 'Project plan');
        $message = $this->message($conversation);

        $this->attachment($user, [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'filename' => 'launch-plan.md',
            'mime_type' => 'text/markdown',
        ]);
        $this->attachment($other, ['filename' => 'private-other-user.png']);

        $response = $this->actingAs($user)
            ->get(route('library.index'))
            ->assertOk()
            ->assertSee('launch-plan.md')
            ->assertSee('Project plan')
            ->assertDontSee('private-other-user.png');

        $response->assertSee('#message-'.$message->id, false);
    }

    public function test_library_can_search_and_filter_files(): void
    {
        $user = User::factory()->create();
        $this->attachment($user, ['filename' => 'diagram.png', 'mime_type' => 'image/png', 'attachment_type' => 'image']);
        $this->attachment($user, ['filename' => 'notes.md', 'mime_type' => 'text/markdown']);

        Livewire::actingAs($user)
            ->test('library.index')
            ->set('type', 'image')
            ->assertSee('diagram.png')
            ->assertDontSee('notes.md')
            ->set('type', '')
            ->set('search', 'notes')
            ->assertSee('notes.md')
            ->assertDontSee('diagram.png');
    }

    public function test_owner_can_preview_and_download_a_stored_file(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Storage::put('attachments/example.txt', 'private attachment contents');
        $attachment = $this->attachment($user, [
            'filename' => 'example.txt',
            'mime_type' => 'text/plain',
            'byte_size' => 27,
            'storage_path' => 'attachments/example.txt',
        ]);

        $this->actingAs($user)
            ->get(route('library.preview', $attachment))
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertStreamedContent('private attachment contents');

        $this->actingAs($user)
            ->get(route('library.download', $attachment))
            ->assertOk()
            ->assertDownload('example.txt');
    }

    public function test_attachment_can_read_a_text_preview_from_storage(): void
    {
        Storage::fake('local');
        Storage::put('attachments/preview.txt', 'Preview contents');

        $attachment = new Attachment([
            'filename' => 'preview.txt',
            'storage_path' => 'attachments/preview.txt',
        ]);

        Assert::assertSame('Preview', $attachment->textPreview(7));
    }

    public function test_user_cannot_access_another_users_attachment(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        Storage::put('attachments/private.txt', 'secret');
        $attachment = $this->attachment($owner, [
            'filename' => 'private.txt',
            'storage_path' => 'attachments/private.txt',
        ]);

        $this->actingAs($other)
            ->get(route('library.preview', $attachment))
            ->assertNotFound();

        $this->actingAs($other)
            ->get(route('library.download', $attachment))
            ->assertNotFound();
    }

    public function test_preview_sanitizes_source_filenames_containing_paths(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        Storage::put('attachments/nested.txt', 'nested');
        $attachment = $this->attachment($user, [
            'filename' => 'generated/folder\\nested.txt',
            'mime_type' => 'text/plain',
            'storage_path' => 'attachments/nested.txt',
        ]);

        $this->actingAs($user)
            ->get(route('library.preview', $attachment))
            ->assertOk()
            ->assertHeaderContains('content-disposition', 'nested.txt');
    }

    private function conversation(User $user, string $title): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'conversation-'.$user->id,
            'title' => $title,
            'metadata' => [],
        ]);
    }

    private function message(Conversation $conversation): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'message-'.$conversation->id,
            'role' => 'user',
            'created_at' => now(),
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attachment(User $user, array $attributes = []): Attachment
    {
        return Attachment::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt->value,
            'source_attachment_id' => 'file-'.uniqid(),
            'attachment_type' => 'file',
            'filename' => 'example.bin',
            'source_ref' => [],
            ...$attributes,
        ]);
    }
}
