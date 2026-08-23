<?php

namespace Tests\Feature\Actions\Imports;

use App\Actions\Imports\WriteCanonicalConversation;
use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\Enums\ImportFormat;
use App\Enums\MessageRole;
use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\ConversationSource;
use App\Models\User;
use App\ValueObjects\CanonicalAttachment;
use App\ValueObjects\CanonicalContentBlock;
use App\ValueObjects\CanonicalConversation;
use App\ValueObjects\CanonicalConversationSource;
use App\ValueObjects\CanonicalMessage;
use App\ValueObjects\ImportContext;
use App\ValueObjects\ProjectIdentifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class WriteCanonicalConversationAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_persists_the_complete_canonical_graph(): void
    {
        $user = User::factory()->create();
        $context = $this->context($user, 'fallback.json');
        $blockAttachment = new CanonicalAttachment(
            AttachmentType::Image,
            sourceAttachmentId: 'shared-attachment',
            filename: 'image.png',
        );
        $canonical = new CanonicalConversation(
            title: 'Canonical graph',
            sourcePlatform: SourcePlatform::ChatGpt,
            sourceConversationId: 'conversation-1',
            messages: [
                new CanonicalMessage(
                    sourceMessageId: 'message-1',
                    parentSourceMessageId: null,
                    role: MessageRole::User,
                    actorName: 'Tom',
                    createdAt: CarbonImmutable::parse('2026-08-23 10:00:00'),
                    isOnCanonicalPath: true,
                    isHidden: false,
                    blocks: [
                        new CanonicalContentBlock(0, BlockType::Text),
                        new CanonicalContentBlock(1, BlockType::ToolResult, structuredContent: ['result' => true]),
                        new CanonicalContentBlock(2, BlockType::Image, attachments: [
                            new CanonicalAttachment(AttachmentType::Image, sourceAttachmentId: ' '),
                        ]),
                    ],
                    metadata: ['kind' => 'prompt'],
                ),
                new CanonicalMessage(
                    sourceMessageId: 'message-2',
                    parentSourceMessageId: 'message-1',
                    role: MessageRole::Assistant,
                    actorName: 'Assistant',
                    createdAt: CarbonImmutable::parse('2026-08-23 11:00:00'),
                    isOnCanonicalPath: true,
                    isHidden: false,
                    blocks: [
                        new CanonicalContentBlock(
                            0,
                            BlockType::Text,
                            textContent: 'Answer',
                            language: 'markdown',
                            metadata: ['visible' => true],
                            attachments: [$blockAttachment],
                        ),
                    ],
                    attachments: [
                        $blockAttachment,
                        new CanonicalAttachment(AttachmentType::File, filename: 'unkeyed.txt'),
                    ],
                ),
                new CanonicalMessage(
                    sourceMessageId: 'message-3',
                    parentSourceMessageId: 'missing-parent',
                    role: MessageRole::Assistant,
                    actorName: null,
                    createdAt: CarbonImmutable::parse('2026-08-23 12:00:00'),
                    isOnCanonicalPath: false,
                    isHidden: true,
                    blocks: [],
                ),
            ],
            metadata: ['model' => 'gpt-5'],
            sources: [
                new CanonicalConversationSource(
                    'conversations.json',
                    ImportFormat::ChatGptJson,
                    'source-checksum',
                    'imports/conversations.json',
                ),
            ],
            canonicalLeafSourceMessageId: 'message-2',
            projectIdentifiers: [
                new ProjectIdentifier(
                    SourcePlatform::ChatGpt,
                    ProjectIdentifierType::ChatGptProject,
                    'project-1',
                ),
            ],
        );

        $conversation = WriteCanonicalConversation::make()->execute($context, $canonical);
        $messages = $conversation->messages()->orderBy('id')->get();

        Assert::assertSame('Canonical graph', $conversation->title);
        Assert::assertSame(['model' => 'gpt-5'], $conversation->metadata);
        Assert::assertSame($messages[1]->id, $conversation->canonical_leaf_message_id);
        Assert::assertSame('2026-08-23 10:00:00', $conversation->first_message_at?->format('Y-m-d H:i:s'));
        Assert::assertSame('2026-08-23 12:00:00', $conversation->last_message_at?->format('Y-m-d H:i:s'));
        Assert::assertSame($messages[0]->id, $messages[1]->parent_message_id);
        Assert::assertNull($messages[2]->parent_message_id);
        Assert::assertSame(['kind' => 'prompt'], $messages[0]->metadata);
        Assert::assertTrue($messages[2]->is_hidden);
        Assert::assertSame(3, ContentBlock::query()->count());
        Assert::assertSame(3, Attachment::query()->count());
        Assert::assertSame(
            1,
            Attachment::query()->where('source_attachment_id', 'shared-attachment')->count(),
        );
        Assert::assertNotNull(
            Attachment::query()->where('source_attachment_id', 'shared-attachment')->sole()->content_block_id,
        );
        Assert::assertSame(1, ConversationSource::query()->count());
        Assert::assertSame('imports/conversations.json', ConversationSource::query()->sole()->raw_storage_path);
        Assert::assertCount(1, $conversation->projects()->get());
    }

    public function test_it_replaces_messages_uses_fallback_leaf_and_records_default_source_idempotently(): void
    {
        $user = User::factory()->create();
        $context = $this->context($user, 'conversation.json');
        $first = $this->simpleConversation([
            $this->message('old-message', '2026-08-23 09:00:00', true),
        ]);
        $conversation = WriteCanonicalConversation::make()->execute($context, $first);
        $oldMessageId = $conversation->messages()->sole()->id;
        $replacement = $this->simpleConversation([
            $this->message('canonical-early', '2026-08-23 10:00:00', true),
            $this->message('noncanonical-late', '2026-08-23 12:00:00', false),
            $this->message('canonical-latest', '2026-08-23 11:00:00', true),
        ], canonicalLeafSourceMessageId: 'missing-message', title: 'Updated title');

        $updated = WriteCanonicalConversation::make()->execute($context, $replacement);

        Assert::assertSame($conversation->id, $updated->id);
        Assert::assertSame('Updated title', $updated->title);
        Assert::assertSame('canonical-latest', $updated->canonicalLeafMessage?->source_message_id);
        $this->assertDatabaseMissing('messages', ['id' => $oldMessageId]);
        $this->assertDatabaseCount('messages', 3);
        $this->assertDatabaseCount('conversation_sources', 1);
        $this->assertDatabaseHas('conversation_sources', [
            'source_file' => 'conversation.json',
            'source_format' => 'chatgpt_json',
            'raw_checksum' => 'context-checksum',
            'raw_storage_path' => 'imports/conversation.json',
        ]);
    }

    public function test_it_handles_a_conversation_without_messages(): void
    {
        $conversation = WriteCanonicalConversation::make()->execute(
            $this->context(User::factory()->create(), 'empty.json'),
            $this->simpleConversation([]),
        );

        Assert::assertNull($conversation->canonical_leaf_message_id);
        Assert::assertNull($conversation->first_message_at);
        Assert::assertNull($conversation->last_message_at);
    }

    /** @param array<int, CanonicalMessage> $messages */
    private function simpleConversation(
        array $messages,
        ?string $canonicalLeafSourceMessageId = null,
        string $title = 'Conversation',
    ): CanonicalConversation {
        return new CanonicalConversation(
            title: $title,
            sourcePlatform: SourcePlatform::ChatGpt,
            sourceConversationId: 'conversation-1',
            messages: $messages,
            canonicalLeafSourceMessageId: $canonicalLeafSourceMessageId,
        );
    }

    private function message(string $sourceId, string $createdAt, bool $canonical): CanonicalMessage
    {
        return new CanonicalMessage(
            sourceMessageId: $sourceId,
            parentSourceMessageId: null,
            role: MessageRole::User,
            actorName: null,
            createdAt: CarbonImmutable::parse($createdAt),
            isOnCanonicalPath: $canonical,
            isHidden: false,
            blocks: [new CanonicalContentBlock(0, BlockType::Text, $sourceId)],
        );
    }

    private function context(User $user, string $sourceFile): ImportContext
    {
        return new ImportContext(
            userId: $user->id,
            filePath: 'imports/'.$sourceFile,
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: 'context-checksum',
            sourceFile: $sourceFile,
        );
    }
}
