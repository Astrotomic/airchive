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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AppTestCase;

class AttachmentLibraryAppTest extends AppTestCase
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

    #[DataProvider('libraryTypes')]
    public function test_library_supports_each_file_type_filter(string $type, string $expected): void
    {
        $user = User::factory()->create();
        $this->seedLibraryTypes($user);

        Livewire::actingAs($user)
            ->test('library.index')
            ->set('type', $type)
            ->assertSee($expected)
            ->assertDontSee('generic-available.bin');
    }

    /** @return iterable<string, array{string, string}> */
    public static function libraryTypes(): iterable
    {
        yield 'image' => ['image', 'filter-image.png'];
        yield 'PDF' => ['pdf', 'filter-document.pdf'];
        yield 'text' => ['text', 'filter-data.json'];
        yield 'document' => ['document', 'filter-sheet.xlsx'];
        yield 'audio' => ['audio', 'filter-audio.mp3'];
        yield 'video' => ['video', 'filter-video.mp4'];
        yield 'archive' => ['archive', 'filter-archive.zip'];
        yield 'artifact' => ['artifact', 'filter-canvas.bin'];
        yield 'unavailable' => ['unavailable', 'filter-unavailable.bin'];
    }

    public function test_library_searches_metadata_and_conversation_and_filters_platform(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user, 'Unique conversation title');
        $message = $this->message($conversation);
        $this->attachment($user, [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'filename' => 'conversation-file.bin',
            'mime_type' => 'application/x-custom',
            'source_attachment_id' => 'file-searchable-id',
            'storage_path' => 'attachments/conversation-file.bin',
        ]);
        $this->attachment($user, [
            'source_platform' => SourcePlatform::Cursor,
            'filename' => 'cursor-only.bin',
            'storage_path' => 'attachments/cursor-only.bin',
        ]);

        Livewire::actingAs($user)
            ->test('library.index')
            ->set('search', 'x-custom')
            ->assertSee('conversation-file.bin')
            ->set('search', 'searchable-id')
            ->assertSee('conversation-file.bin')
            ->set('search', 'Unique conversation')
            ->assertSee('conversation-file.bin')
            ->set('search', '')
            ->set('platform', SourcePlatform::Cursor->value)
            ->assertSee('cursor-only.bin')
            ->assertDontSee('conversation-file.bin');
    }

    public function test_library_supports_each_sort_and_clears_filters(): void
    {
        $user = User::factory()->create();
        $alpha = $this->attachment($user, [
            'filename' => 'alpha.bin',
            'byte_size' => 20,
            'storage_path' => 'attachments/alpha.bin',
            'created_at' => now()->subDay(),
        ]);
        $beta = $this->attachment($user, [
            'filename' => 'beta.bin',
            'byte_size' => 10,
            'storage_path' => 'attachments/beta.bin',
            'created_at' => now(),
        ]);

        Livewire::actingAs($user)
            ->test('library.index')
            ->set('sort', 'oldest')
            ->assertSeeInOrder([$alpha->filename, $beta->filename])
            ->set('sort', 'name')
            ->assertSeeInOrder([$alpha->filename, $beta->filename])
            ->set('sort', 'largest')
            ->assertSeeInOrder([$alpha->filename, $beta->filename])
            ->set('sort', 'newest')
            ->assertSeeInOrder([$beta->filename, $alpha->filename])
            ->set('search', 'alpha')
            ->set('type', 'unavailable')
            ->set('platform', SourcePlatform::Cursor->value)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('type', '')
            ->assertSet('platform', '')
            ->assertSet('sort', 'newest');
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

    public function test_text_preview_rejects_unsupported_missing_and_empty_contents(): void
    {
        Storage::fake('local');
        Storage::put('attachments/image.png', 'image');
        Storage::put('attachments/empty.txt', '');

        Assert::assertNull((new Attachment([
            'filename' => 'image.png',
            'mime_type' => 'image/png',
            'storage_path' => 'attachments/image.png',
        ]))->textPreview());
        Assert::assertNull((new Attachment(['filename' => 'notes.txt']))->textPreview());
        Assert::assertNull((new Attachment([
            'filename' => 'missing.txt',
            'storage_path' => 'attachments/missing.txt',
        ]))->textPreview());
        Assert::assertNull((new Attachment([
            'filename' => 'empty.txt',
            'storage_path' => 'attachments/empty.txt',
        ]))->textPreview());
    }

    public function test_text_preview_scrubs_invalid_utf8_and_supports_artifacts(): void
    {
        Storage::fake('local');
        Storage::put('attachments/artifact.txt', "Valid\xC3\x28 text");
        $attachment = new Attachment([
            'attachment_type' => 'canvas',
            'storage_path' => 'attachments/artifact.txt',
        ]);

        $preview = $attachment->textPreview();

        Assert::assertNotNull($preview);
        Assert::assertTrue(mb_check_encoding($preview, 'UTF-8'));
        Assert::assertStringContainsString('Valid', $preview);
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

    public function test_external_attachments_redirect_and_unavailable_attachments_return_not_found(): void
    {
        $user = User::factory()->create();
        $external = $this->attachment($user, [
            'external_url' => 'https://example.com/file.pdf',
        ]);
        $unavailable = $this->attachment($user, [
            'external_url' => 'javascript:alert(1)',
        ]);

        $this->actingAs($user)
            ->get(route('library.preview', $external))
            ->assertRedirect('https://example.com/file.pdf');
        $this->actingAs($user)
            ->get(route('library.download', $external))
            ->assertRedirect('https://example.com/file.pdf');
        $this->actingAs($user)
            ->get(route('library.preview', $unavailable))
            ->assertNotFound();
    }

    public function test_stored_attachment_uses_safe_fallback_filename_and_headers(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Storage::put('attachments/anonymous.bin', 'binary');
        $attachment = $this->attachment($user, [
            'filename' => "folder/\x00\x1F",
            'mime_type' => null,
            'storage_path' => 'attachments/anonymous.bin',
        ]);

        $this->actingAs($user)
            ->get(route('library.download', $attachment))
            ->assertOk()
            ->assertDownload('attachment-'.$attachment->id)
            ->assertHeader('content-type', 'application/octet-stream')
            ->assertHeaderContains('cache-control', 'private')
            ->assertHeaderContains('cache-control', 'max-age=3600');
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

    private function seedLibraryTypes(User $user): void
    {
        foreach ([
            ['filename' => 'filter-image.png', 'mime_type' => 'image/png', 'attachment_type' => 'image'],
            ['filename' => 'filter-document.pdf', 'mime_type' => 'application/pdf'],
            ['filename' => 'filter-data.json', 'mime_type' => 'application/json'],
            ['filename' => 'filter-sheet.xlsx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['filename' => 'filter-audio.mp3', 'mime_type' => 'audio/mpeg'],
            ['filename' => 'filter-video.mp4', 'mime_type' => 'video/mp4'],
            ['filename' => 'filter-archive.zip', 'mime_type' => 'application/zip'],
            ['filename' => 'filter-canvas.bin', 'attachment_type' => 'canvas'],
            ['filename' => 'generic-available.bin'],
        ] as $attributes) {
            $this->attachment($user, [
                'storage_path' => 'attachments/'.$attributes['filename'],
                ...$attributes,
            ]);
        }

        $this->attachment($user, [
            'filename' => 'filter-unavailable.bin',
            'storage_path' => null,
            'external_url' => null,
        ]);
    }
}
