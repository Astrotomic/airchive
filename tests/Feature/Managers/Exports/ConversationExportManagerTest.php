<?php

namespace Tests\Feature\Managers\Exports;

use App\Enums\BlockType;
use App\Enums\ExportFormat;
use App\Enums\SourcePlatform;
use App\Managers\Exports\ConversationExportManager;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ConversationExportManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_markdown_export_includes_title_and_canonical_messages(): void
    {
        $user = User::factory()->create();

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => 'conv-1',
            'title' => 'Export test',
            'metadata' => [],
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'assistant1',
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
            'text_content' => 'Canonical answer text.',
        ]);

        $markdown = app(ConversationExportManager::class)->export($conversation, ExportFormat::Markdown);

        Assert::assertStringContainsString('# Export test', $markdown);
        Assert::assertStringContainsString('Canonical answer text.', $markdown);
    }
}
