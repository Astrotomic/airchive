<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\ParseCursorJsonlConversation;
use App\Enums\BlockType;
use App\Enums\ImportFormat;
use App\Enums\MessageRole;
use App\ValueObjects\ImportContext;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ParseCursorJsonlConversationTest extends UnitTestCase
{
    #[DataProvider('invalidTranscripts')]
    public function test_it_rejects_invalid_transcripts(string $contents, string $message): void
    {
        try {
            ParseCursorJsonlConversation::make()->execute($this->context(), $contents);
            Assert::fail('Invalid Cursor transcript was accepted.');
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame($message, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTranscripts(): iterable
    {
        yield 'empty' => [" \n\r", 'Cursor JSONL export contains no messages.'];
        yield 'invalid first line' => ["not-json\n{}", 'Cursor JSONL export contains invalid JSON on line 1.'];
        yield 'scalar JSON' => ["{}\n42", 'Cursor JSONL export contains invalid JSON on line 2.'];
    }

    #[DataProvider('conversationIds')]
    public function test_it_resolves_conversation_ids(
        array $row,
        ImportContext $context,
        string $expected,
    ): void {
        $conversation = ParseCursorJsonlConversation::make()->execute($context, $this->jsonl([$row]));

        Assert::assertSame($expected, $conversation->sourceConversationId);
    }

    /** @return iterable<string, array{array<string, mixed>, ImportContext, string}> */
    public static function conversationIds(): iterable
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';

        yield 'session ID precedence' => [[
            'session_id' => 'session-1',
            'conversation_id' => 'conversation-1',
            'content' => [],
        ], self::contextFor(), 'session-1'];
        yield 'conversation ID' => [['conversation_id' => 'conversation-1', 'content' => []], self::contextFor(), 'conversation-1'];
        yield 'chat ID' => [['chat_id' => 42, 'content' => []], self::contextFor(), '42'];
        yield 'composer ID' => [['composer_id' => 'composer-1', 'content' => []], self::contextFor(), 'composer-1'];
        yield 'UUID filename' => [['content' => []], self::contextFor(sourceFile: $uuid.'.jsonl'), $uuid];
        yield 'workspace UUID' => [['content' => []], self::contextFor(
            sourceFile: $uuid.'.jsonl',
            metadata: ['cursor_workspace' => 'workspace-1', 'cursor_parent_transcript_id' => 'parent-1'],
        ), 'workspace-1:parent-1:'.$uuid];
        yield 'workspace UUID without string parent' => [['content' => []], self::contextFor(
            sourceFile: $uuid.'.jsonl',
            metadata: ['cursor_workspace' => 'workspace-1', 'cursor_parent_transcript_id' => 42],
        ), 'workspace-1:'.$uuid];
        yield 'filename and checksum' => [['content' => []], self::contextFor(
            filePath: 'imports/My Conversation.jsonl',
            sourceFile: null,
            checksum: 'abcdef1234567890',
        ), 'my-conversation-abcdef123456'];
    }

    public function test_it_resolves_explicit_derived_and_file_titles(): void
    {
        $action = ParseCursorJsonlConversation::make();

        Assert::assertSame('Explicit', $action->execute($this->context(), $this->jsonl([
            ['title' => 'Explicit', 'content' => []],
        ]))->title);
        Assert::assertSame('Conversation title', $action->execute($this->context(), $this->jsonl([
            ['conversation_title' => 'Conversation title', 'content' => []],
        ]))->title);
        Assert::assertSame('Named', $action->execute($this->context(), $this->jsonl([
            ['name' => 'Named', 'content' => []],
        ]))->title);
        Assert::assertSame('Build the archive', $action->execute($this->context(), $this->jsonl([
            ['title' => 123, 'role' => 'user', 'content' => [['type' => 'text', 'text' => 'Build the archive']]],
        ]))->title);
        Assert::assertSame('Cursor Transcript', $action->execute(
            $this->context(filePath: 'imports/cursor_transcript.jsonl'),
            $this->jsonl([['role' => 'assistant', 'content' => []]]),
        )->title);
        Assert::assertSame('Untitled conversation', $action->execute(
            $this->context(filePath: '', sourceFile: ''),
            $this->jsonl([['role' => 'assistant', 'content' => []]]),
        )->title);
    }

    public function test_it_resolves_message_identity_parentage_roles_timestamps_and_metadata(): void
    {
        $rows = [
            [
                'message_id' => 'message-1',
                'parent_message_id' => '',
                'type' => 'custom-role',
                'timestamp' => 1_700_000_000,
                'name' => 'Actor',
                'metadata' => ['model' => 'cursor'],
                'content' => [],
            ],
            [
                'uuid' => 'message-2',
                'parent_id' => 'message-1',
                'role' => 'assistant',
                'created_at' => 1_700_000_000_123,
                'content' => [],
            ],
            [
                'role' => 'system',
                'createdAt' => '2026-08-23 12:00:00',
                'content' => [],
            ],
            [
                'id' => 'message-4',
                'role' => 'user',
                'content' => ['<timestamp>2026-08-23 13:00:00</timestamp><user_query>Question</user_query>'],
            ],
            [
                'id' => 'message-5',
                'role' => 'assistant',
                'content' => 'invalid-content',
            ],
        ];

        $conversation = ParseCursorJsonlConversation::make()->execute($this->context(), $this->jsonl($rows));
        $messages = $conversation->messages;

        Assert::assertSame('message-1', $messages[0]->sourceMessageId);
        Assert::assertSame('', $messages[0]->parentSourceMessageId);
        Assert::assertSame(MessageRole::Unknown, $messages[0]->role);
        Assert::assertSame('custom-role', $messages[0]->metadata['_source_role']);
        Assert::assertSame('cursor', $messages[0]->metadata['model']);
        Assert::assertSame('Actor', $messages[0]->actorName);
        Assert::assertSame(1_700_000_000, $messages[0]->createdAt?->timestamp);
        Assert::assertSame('message-1', $messages[1]->parentSourceMessageId);
        Assert::assertSame('1700000000123', $messages[1]->createdAt?->format('Uv'));
        Assert::assertSame('line-3', $messages[2]->sourceMessageId);
        Assert::assertSame('message-2', $messages[2]->parentSourceMessageId);
        Assert::assertSame('2026-08-23 12:00:00', $messages[2]->createdAt?->format('Y-m-d H:i:s'));
        Assert::assertSame('line-3', $messages[3]->parentSourceMessageId);
        Assert::assertSame('2026-08-23 13:00:00', $messages[3]->createdAt?->format('Y-m-d H:i:s'));
        Assert::assertSame($messages[3]->createdAt?->timestamp, $messages[4]->createdAt?->timestamp);
        Assert::assertSame([], $messages[4]->blocks);
        Assert::assertSame('message-5', $conversation->canonicalLeafSourceMessageId);
    }

    public function test_it_maps_all_content_item_types_and_visibility(): void
    {
        $conversation = ParseCursorJsonlConversation::make()->execute(
            $this->context(metadata: ['cursor_workspace' => 'workspace-1']),
            $this->jsonl([
                [
                    'id' => 'user',
                    'role' => 'user',
                    'content' => [
                        '<user_query>Plain string</user_query>',
                        ' ',
                        42,
                        ['type' => 'text', 'content' => '<user_query>Text item</user_query>'],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'tool_use', 'name' => 'Read', 'input' => ['path' => '/tmp/file']],
                        ['type' => 'tool_use'],
                        ['type' => 'tool_result'],
                        ['type' => 'tool_result', 'name' => 'Read', 'output' => ['contents' => 'result']],
                        ['type' => 'tool_result', 'text' => 'present'],
                        ['type' => 'thinking', 'thinking' => 'Reasoning'],
                        ['type' => 'image', 'source' => ['url' => 'https://example.com/image.png']],
                        ['type' => 'custom', 'text' => 'Custom text'],
                        ['type' => 'custom', 'data' => true],
                        [],
                    ],
                ],
                [
                    'id' => 'reasoning-only',
                    'role' => 'assistant',
                    'content' => [['type' => 'thinking', 'content' => 'Only reasoning']],
                ],
                [
                    'id' => 'empty',
                    'role' => 'assistant',
                    'content' => [],
                ],
                [
                    'id' => 'tool',
                    'role' => 'tool',
                    'content' => ['  Tool output  '],
                ],
            ]),
        );
        [$user, $reasoningOnly, $empty, $tool] = $conversation->messages;

        Assert::assertSame([
            BlockType::Text,
            BlockType::Text,
            BlockType::ToolUse,
            BlockType::ToolUse,
            BlockType::ToolResult,
            BlockType::ToolResult,
            BlockType::Reasoning,
            BlockType::Image,
            BlockType::Other,
            BlockType::Other,
        ], array_column($user->blocks, 'blockType'));
        Assert::assertSame(range(0, 9), array_column($user->blocks, 'position'));
        Assert::assertSame('Plain string', $user->blocks[0]->textContent);
        Assert::assertSame('Text item', $user->blocks[1]->textContent);
        Assert::assertSame('tool', $user->blocks[3]->metadata['tool_name']);
        Assert::assertSame('Read', $user->blocks[4]->metadata['tool_name']);
        Assert::assertSame('tool_result', $user->blocks[5]->metadata['tool_name']);
        Assert::assertSame('https://example.com/image.png', $user->blocks[7]->attachments[0]->externalUrl);
        Assert::assertNull($user->blocks[9]->textContent);
        Assert::assertFalse($user->isHidden);
        Assert::assertTrue($reasoningOnly->isHidden);
        Assert::assertFalse($empty->isHidden);
        Assert::assertSame('Tool output', $tool->blocks[0]->textContent);
        Assert::assertCount(1, $conversation->projectIdentifiers);
    }

    private function context(
        string $filePath = 'imports/transcript.jsonl',
        ?string $sourceFile = 'transcript.jsonl',
        string $checksum = 'abcdef1234567890',
        array $metadata = [],
    ): ImportContext {
        return self::contextFor($filePath, $sourceFile, $checksum, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private static function contextFor(
        string $filePath = 'imports/transcript.jsonl',
        ?string $sourceFile = 'transcript.jsonl',
        string $checksum = 'abcdef1234567890',
        array $metadata = [],
    ): ImportContext {
        return new ImportContext(
            userId: 1,
            filePath: $filePath,
            sourceFormat: ImportFormat::CursorJsonl,
            rawChecksum: $checksum,
            sourceFile: $sourceFile,
            metadata: $metadata,
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function jsonl(array $rows): string
    {
        return implode("\n", array_map(
            static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR),
            $rows,
        ));
    }
}
