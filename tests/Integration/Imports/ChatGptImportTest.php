<?php

namespace Tests\Integration\Imports;

use App\Actions\Imports\ParseChatGptConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Enums\BlockType;
use App\Enums\ImportFormat;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\ValueObjects\ImportContext;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ChatGptImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_canonical_path_only_for_thread_view_count(): void
    {
        $user = User::factory()->create();
        $contents = file_get_contents(base_path('tests/Fixtures/chatgpt_with_branch.json'));

        Assert::assertNotFalse($contents);

        $context = new ImportContext(
            userId: $user->id,
            filePath: 'fixtures/chatgpt_with_branch.json',
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: hash('sha256', $contents),
        );

        [$canonical] = ParseChatGptConversation::make()->execute($context, $contents);
        WriteCanonicalConversation::make()->execute($context, $canonical);
        WriteCanonicalConversation::make()->execute($context, $canonical);

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->first();

        Assert::assertNotNull($conversation);
        Assert::assertSame('WordPress help', $conversation->title);
        Assert::assertSame(1_700_000_000, $conversation->first_message_at?->timestamp);
        Assert::assertSame(1_700_000_300, $conversation->last_message_at?->timestamp);
        $this->assertDatabaseCount('conversation_sources', 1);

        Assert::assertSame(4, Message::query()->where('conversation_id', $conversation->id)->count());
        Assert::assertSame(
            3,
            Message::query()->where('conversation_id', $conversation->id)->where('is_on_canonical_path', true)->count(),
        );

        Assert::assertFalse(
            Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('source_message_id', 'assistant1')
                ->value('is_on_canonical_path'),
        );
    }

    public function test_imports_tool_calls_and_results_using_canonical_tool_blocks(): void
    {
        $user = User::factory()->create();
        $contents = file_get_contents(base_path('tests/Fixtures/chatgpt_with_tool.json'));

        Assert::assertNotFalse($contents);

        $context = new ImportContext(
            userId: $user->id,
            filePath: 'fixtures/chatgpt_with_tool.json',
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: hash('sha256', $contents),
        );

        [$canonical] = ParseChatGptConversation::make()->execute($context, $contents);
        $conversation = WriteCanonicalConversation::make()->execute($context, $canonical);

        $toolUse = ContentBlock::query()
            ->where('block_type', BlockType::ToolUse)
            ->first();
        $toolResult = ContentBlock::query()
            ->where('block_type', BlockType::ToolResult)
            ->first();

        Assert::assertNotNull($toolUse);
        Assert::assertSame('web.run', $toolUse->metadata['tool_name']);
        Assert::assertSame('web.run', $toolUse->structured_content['name']);
        Assert::assertSame(
            'Instagram export',
            $toolUse->structured_content['input']['search_query'][0]['q'],
        );

        Assert::assertNotNull($toolResult);
        $sourceUrl = $toolResult->structured_content['output']['search_result_groups'][0]['entries'][0]['url'];
        UrlAssertions::assertValidLoose($sourceUrl);
        Assert::assertSame('web.run', $toolResult->metadata['tool_name']);
        Assert::assertSame('web.run', $toolResult->structured_content['name']);
        Assert::assertSame(
            'Instagram Help Center',
            $toolResult->structured_content['output']['search_result_groups'][0]['entries'][0]['title'],
        );

        Assert::assertFalse(ContentBlock::query()->where('block_type', BlockType::Code)->exists());

        $answer = ContentBlock::query()
            ->where('block_type', BlockType::Text)
            ->whereHas('message', fn ($query) => $query->where('source_message_id', 'assistant-final'))
            ->value('text_content');

        Assert::assertStringContainsString(
            '([Instagram](https://example.com/help))',
            $answer,
        );
        Assert::assertStringNotContainsString('cite', $answer);

        $this->actingAs($user)
            ->get(route('conversations.show', $conversation))
            ->assertOk()
            ->assertSee('External sources')
            ->assertSee('Instagram Help Center')
            ->assertSee('href="https://example.com/help"', false)
            ->assertSee('title="https://example.com/help"', false)
            ->assertSee('example.com')
            ->assertSee('>/help</span>', false)
            ->assertSee('src="https://t1.gstatic.com/faviconV2?', false);
    }
}
