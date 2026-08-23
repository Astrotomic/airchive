<?php

namespace Tests\Feature\Managers\Exports;

use App\Enums\BlockType;
use App\Enums\ExportFormat;
use App\Enums\SourcePlatform;
use App\Managers\Exports\ConversationExportManager;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\AppTestCase;

class ConversationExportManagerAppTest extends AppTestCase
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

    public function test_markdown_and_html_exports_render_each_block_type_and_escape_content(): void
    {
        [$conversation, $message] = $this->conversationWithMessage('<Export #test>', true, 'assistant', 'Agent');
        $blocks = [
            [BlockType::Code, '<script>alert(1)</script>', 'php'],
            [BlockType::Image, 'https://example.com/image.png', null],
            [BlockType::ToolUse, 'Read <file>', null],
            [BlockType::ToolResult, 'Result & output', null],
            [BlockType::Reasoning, 'Think > answer', null],
            [BlockType::Text, 'Plain <strong>text</strong>', null],
        ];

        foreach ($blocks as $position => [$type, $text, $language]) {
            ContentBlock::query()->create([
                'message_id' => $message->id,
                'position' => $position,
                'block_type' => $type,
                'text_content' => $text,
                'language' => $language,
            ]);
        }
        $branch = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => fake()->uuid(),
            'role' => 'user',
            'created_at' => now()->addSecond(),
            'is_on_canonical_path' => false,
            'is_hidden' => false,
            'metadata' => [],
        ]);
        ContentBlock::query()->create([
            'message_id' => $branch->id,
            'position' => 0,
            'block_type' => BlockType::Text,
            'text_content' => 'Must not be exported',
        ]);
        $exports = app(ConversationExportManager::class);

        $markdown = $exports->export($conversation, ExportFormat::Markdown);
        $html = $exports->export($conversation, ExportFormat::Html);

        Assert::assertStringContainsString('# <Export \#test>', $markdown);
        Assert::assertStringContainsString('## Assistant (Agent)', $markdown);
        Assert::assertStringContainsString("```php\n<script>alert(1)</script>\n```", $markdown);
        Assert::assertStringContainsString('![](https://example.com/image.png)', $markdown);
        Assert::assertStringContainsString('**Tool use**', $markdown);
        Assert::assertStringContainsString('**Tool result**', $markdown);
        Assert::assertStringContainsString('_Reasoning:_', $markdown);
        Assert::assertStringNotContainsString('Must not be exported', $markdown);

        Assert::assertStringContainsString('<title>&lt;Export #test&gt;</title>', $html);
        Assert::assertStringContainsString('Assistant (Agent)', $html);
        Assert::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        Assert::assertStringContainsString('<img src="https://example.com/image.png" alt="">', $html);
        Assert::assertStringContainsString('class="tool-use"', $html);
        Assert::assertStringContainsString('class="tool-result"', $html);
        Assert::assertStringContainsString('class="reasoning"', $html);
        Assert::assertStringContainsString('Plain &lt;strong&gt;text&lt;/strong&gt;', $html);
        Assert::assertStringNotContainsString('Must not be exported', $html);
    }

    public function test_image_exports_can_use_attachment_urls_and_omit_unavailable_images(): void
    {
        [$conversation, $message] = $this->conversationWithMessage(null, true, 'user');
        $withAttachment = ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::Image,
        ]);
        ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 1,
            'block_type' => BlockType::Image,
        ]);
        Attachment::query()->create([
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'content_block_id' => $withAttachment->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'attachment_type' => 'image',
            'external_url' => 'https://example.com/attachment.png',
            'source_ref' => [],
        ]);
        $exports = app(ConversationExportManager::class);

        $markdown = $exports->export($conversation, ExportFormat::Markdown);
        $html = $exports->export($conversation, ExportFormat::Html);

        Assert::assertStringStartsWith('# Untitled conversation', $markdown);
        Assert::assertStringContainsString('![](https://example.com/attachment.png)', $markdown);
        Assert::assertSame(1, substr_count($html, '<img '));
    }

    public function test_json_export_serializes_the_canonical_graph(): void
    {
        [$conversation, $message] = $this->conversationWithMessage('JSON export', true, 'assistant');
        $block = ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::Code,
            'text_content' => 'echo 1;',
            'structured_content' => ['result' => 1],
            'language' => 'php',
            'metadata' => ['collapsed' => false],
        ]);
        $direct = $this->attachment($conversation, $message, null, 'direct.txt');
        $nested = $this->attachment($conversation, $message, $block, 'nested.txt');

        $json = app(ConversationExportManager::class)->export($conversation, ExportFormat::Json);
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        Assert::assertSame('chatgpt', $payload['source_platform']);
        Assert::assertSame('assistant', $payload['messages'][0]['role']);
        Assert::assertSame($direct->id, $payload['messages'][0]['attachments'][0]['id']);
        Assert::assertSame('code', $payload['messages'][0]['content_blocks'][0]['block_type']);
        Assert::assertSame($nested->id, $payload['messages'][0]['content_blocks'][0]['attachments'][0]['id']);
        Assert::assertSame(['result' => 1], $payload['messages'][0]['content_blocks'][0]['structured_content']);
    }

    public function test_json_export_reports_unencodable_content(): void
    {
        [$conversation] = $this->conversationWithMessage('Invalid JSON', true, 'user');
        $conversation->title = "Invalid \xB1 title";

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode conversation as JSON.');

        app(ConversationExportManager::class)->export($conversation, ExportFormat::Json);
    }

    /** @return array{Conversation, Message} */
    private function conversationWithMessage(
        ?string $title,
        bool $canonical,
        string $role,
        ?string $actor = null,
    ): array {
        $user = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => fake()->uuid(),
            'title' => $title,
            'metadata' => ['source' => 'test'],
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => fake()->uuid(),
            'role' => $role,
            'actor_name' => $actor,
            'created_at' => now(),
            'is_on_canonical_path' => $canonical,
            'is_hidden' => false,
            'metadata' => ['model' => 'gpt-5'],
        ]);

        return [$conversation, $message];
    }

    private function attachment(
        Conversation $conversation,
        Message $message,
        ?ContentBlock $block,
        string $filename,
    ): Attachment {
        return Attachment::query()->create([
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
            'content_block_id' => $block?->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_attachment_id' => 'file-'.fake()->unique()->randomNumber(),
            'attachment_type' => 'file',
            'filename' => $filename,
            'mime_type' => 'text/plain',
            'byte_size' => 12,
            'checksum' => str_repeat('a', 64),
            'storage_path' => 'attachments/'.$filename,
            'source_ref' => ['test' => true],
        ]);
    }
}
