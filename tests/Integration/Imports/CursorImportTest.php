<?php

namespace Tests\Integration\Imports;

use App\Actions\Imports\ParseCursorJsonlConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Enums\BlockType;
use App\Enums\ImportFormat;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\ValueObjects\ImportContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class CursorImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_cursor_jsonl_transcript(): void
    {
        $user = User::factory()->create();
        $contents = file_get_contents(base_path('tests/Fixtures/cursor_transcript.jsonl'));

        Assert::assertNotFalse($contents);

        $context = new ImportContext(
            userId: $user->id,
            filePath: 'fixtures/cursor_transcript.jsonl',
            sourceFormat: ImportFormat::CursorJsonl,
            rawChecksum: hash('sha256', $contents),
        );

        $canonical = ParseCursorJsonlConversation::make()->execute($context, $contents);
        WriteCanonicalConversation::make()->execute($context, $canonical);

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->first();

        Assert::assertNotNull($conversation);
        Assert::assertSame('How do I debug a Laravel queue worker?', $conversation->title);
        Assert::assertSame(2, Message::query()->where('conversation_id', $conversation->id)->count());

        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('created_at')
            ->get();

        Assert::assertCount(2, $messages);
        Assert::assertNotNull($messages[0]->created_at);
        Assert::assertSame(
            '2026-07-12',
            $messages[0]->created_at->toDateString(),
        );
        Assert::assertNotNull($messages[1]->created_at);
        Assert::assertTrue($messages[1]->created_at->equalTo($messages[0]->created_at));

        Assert::assertTrue(
            ContentBlock::query()
                ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversation->id))
                ->where('block_type', BlockType::ToolUse)
                ->exists(),
        );
    }
}
