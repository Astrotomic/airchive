<?php

namespace Tests\Feature\Livewire;

use App\Enums\BlockType;
use App\Enums\MessageRole;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\ConversationSource;
use App\Models\Message;
use App\Models\User;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ConversationShowAppTest extends AppTestCase
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

    public function test_filters_and_groups_messages_while_preserving_complete_conversation_metadata(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'branched-conversation',
            'title' => 'Branched conversation',
            'metadata' => [
                'default_model_slug' => 'gpt-5',
                'shared_conversations' => [
                    ['source_url' => 'https://chatgpt.com/share/shared-test'],
                    ['source_url' => 'ftp://example.com/not-supported'],
                    'invalid-entry',
                ],
                'source_url' => 'https://chatgpt.com/share/shared-test',
                'url' => 'javascript:alert(1)',
            ],
        ]);

        $first = $this->createTextMessage($conversation, 'first-user', MessageRole::User, 'First question', 1, metadata: [
            'model_slug' => 'gpt-5-thinking',
        ]);
        $second = $this->createTextMessage($conversation, 'second-user', MessageRole::User, 'Second question', 2, metadata: [
            'resolved_model_slug' => 'gpt-5-thinking',
        ]);
        $branch = $this->createTextMessage($conversation, 'branch-answer', MessageRole::Assistant, 'Alternative answer', 3, canonical: false);
        $this->createTextMessage($conversation, 'hidden-answer', MessageRole::Assistant, 'Hidden answer', 4, hidden: true);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'empty-system-message',
            'role' => MessageRole::System,
            'created_at' => Carbon::create(2026, 1, 1, 0, 0, 5),
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => [],
        ]);

        $component = Livewire::actingAs($user)
            ->test('conversation-show', ['conversation' => $conversation])
            ->assertSee('First question')
            ->assertSee('Second question')
            ->assertDontSee('Alternative answer')
            ->assertDontSee('Hidden answer');

        $turns = $component->viewData('turns');
        Assert::assertCount(1, $turns);
        Assert::assertSame([$first->id, $second->id], $turns->first()['message_ids']);
        Assert::assertCount(2, $turns->first()['blocks']);

        $stats = $component->viewData('conversationStats');
        Assert::assertSame(1, $stats['hidden_count']);
        Assert::assertSame(['user' => 2, 'assistant' => 2, 'system' => 1], $stats['roles']->all());
        Assert::assertSame(['GPT5', 'GPT5t'], $component->viewData('conversationModels')->all());
        Assert::assertSame([
            ['label' => 'Open in ChatGPT', 'url' => 'https://chatgpt.com/c/branched-conversation'],
            ['label' => 'Shared conversation', 'url' => 'https://chatgpt.com/share/shared-test'],
        ], $component->viewData('conversationUrls')->all());

        $component
            ->set('showBranches', true)
            ->assertSee('Alternative answer')
            ->assertDontSee('Hidden answer');

        $turnsWithBranches = $component->viewData('turns');
        Assert::assertCount(2, $turnsWithBranches);
        Assert::assertSame([$branch->id], $turnsWithBranches->last()['message_ids']);
        Assert::assertFalse($turnsWithBranches->last()['is_on_canonical_path']);
    }

    /** @param array<string, mixed> $metadata */
    private function createTextMessage(
        Conversation $conversation,
        string $sourceId,
        MessageRole $role,
        string $text,
        int $second,
        bool $canonical = true,
        bool $hidden = false,
        array $metadata = [],
    ): Message {
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => $sourceId,
            'role' => $role,
            'created_at' => Carbon::create(2026, 1, 1, 0, 0, $second),
            'is_on_canonical_path' => $canonical,
            'is_hidden' => $hidden,
            'metadata' => $metadata,
        ]);
        ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::Text,
            'text_content' => $text,
        ]);

        return $message;
    }
}
