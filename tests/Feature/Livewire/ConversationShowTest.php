<?php

namespace Tests\Feature\Livewire;

use App\Enums\BlockType;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\ConversationSource;
use App\Models\Message;
use App\Models\User;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_displays_a_generated_image_attached_directly_to_a_tool_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'generated-image-conversation',
            'title' => 'Generated image',
            'metadata' => [
                'default_model_slug' => 'gpt-5-2-thinking',
                'shared_conversations' => [[
                    'source_url' => 'https://chatgpt.com/share/shared-test',
                ]],
            ],
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'image-tool-result',
            'role' => 'tool',
            'created_at' => now(),
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => ['model_slug' => 'gpt-5-2-auto-thinking'],
        ]);
        ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::ToolResult,
            'text_content' => 'Generated an image.',
            'structured_content' => ['name' => 'image_generation'],
            'metadata' => ['tool_name' => 'image_generation'],
        ]);
        $attachment = Attachment::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'source_platform' => SourcePlatform::ChatGpt->value,
            'source_attachment_id' => 'file_generated_image',
            'attachment_type' => 'image',
            'filename' => 'generated-cover.png',
            'mime_type' => 'image/png',
            'byte_size' => 1234,
            'storage_path' => 'attachments/generated-cover.png',
            'source_ref' => [],
        ]);
        ConversationSource::query()->create([
            'conversation_id' => $conversation->id,
            'imported_at' => now(),
            'source_file' => 'export/conversations-000.json',
            'source_format' => 'chatgpt_zip',
            'raw_checksum' => str_repeat('a', 64),
            'raw_storage_path' => '/tmp/export.zip',
        ]);
        $previewUrl = route('library.preview', $attachment);
        $downloadUrl = route('library.download', $attachment);

        UrlAssertions::assertValidLoose($previewUrl);
        UrlAssertions::assertValidLoose($downloadUrl);
        $this->actingAs($user)
            ->get(route('conversations.show', $conversation).'#message-'.$message->id)
            ->assertOk()
            ->assertSee('id="message-'.$message->id.'"', false)
            ->assertSee('generated-cover.png')
            ->assertSee('src="'.$previewUrl.'"', false)
            ->assertSee($downloadUrl, false)
            ->assertSee('Conversation details')
            ->assertSee('Models used')
            ->assertSee('GPT5.2t')
            ->assertDontSee('gpt-5-2-thinking')
            ->assertDontSee('gpt-5-2-auto-thinking')
            ->assertSee('Shared conversation')
            ->assertDontSee('conversations-000.json');
    }

    public function test_displays_an_attachment_only_message(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'attachment-only-conversation',
            'title' => 'Attachment only',
            'metadata' => [],
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'attachment-only-message',
            'role' => 'assistant',
            'created_at' => now(),
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => [],
        ]);
        Attachment::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'source_platform' => SourcePlatform::ChatGpt->value,
            'source_attachment_id' => 'file_attachment_only',
            'attachment_type' => 'file',
            'filename' => 'artifact.pdf',
            'mime_type' => 'application/pdf',
            'storage_path' => 'attachments/artifact.pdf',
            'source_ref' => [],
        ]);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('artifact.pdf')
            ->assertDontSee('No messages in this view.');
    }
}
