<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\ImportFormat;
use App\Enums\MessageRole;
use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\ValueObjects\CanonicalConversation;
use App\ValueObjects\CanonicalConversationSource;
use App\ValueObjects\CanonicalMessage;
use App\ValueObjects\ProjectIdentifier;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class CanonicalConversationTest extends UnitTestCase
{
    public function test_it_stores_conversation_data(): void
    {
        $message = new CanonicalMessage(
            sourceMessageId: 'message-1',
            parentSourceMessageId: null,
            role: MessageRole::User,
            actorName: null,
            createdAt: null,
            isOnCanonicalPath: true,
            isHidden: false,
            blocks: [],
        );
        $source = new CanonicalConversationSource(
            sourceFile: 'conversations.json',
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: 'checksum',
            rawStoragePath: 'imports/conversations.json',
        );
        $identifier = new ProjectIdentifier(
            SourcePlatform::ChatGpt,
            ProjectIdentifierType::ChatGptProject,
            'g-p-project',
        );
        $conversation = new CanonicalConversation(
            title: 'Example conversation',
            sourcePlatform: SourcePlatform::ChatGpt,
            sourceConversationId: 'conversation-1',
            messages: [$message],
            metadata: ['model' => 'gpt-5'],
            sources: [$source],
            canonicalLeafSourceMessageId: 'message-1',
            projectIdentifiers: [$identifier],
        );

        Assert::assertSame('Example conversation', $conversation->title);
        Assert::assertSame(SourcePlatform::ChatGpt, $conversation->sourcePlatform);
        Assert::assertSame('conversation-1', $conversation->sourceConversationId);
        Assert::assertSame([$message], $conversation->messages);
        Assert::assertSame(['model' => 'gpt-5'], $conversation->metadata);
        Assert::assertSame([$source], $conversation->sources);
        Assert::assertSame('message-1', $conversation->canonicalLeafSourceMessageId);
        Assert::assertSame([$identifier], $conversation->projectIdentifiers);
    }

    public function test_optional_conversation_data_has_empty_defaults(): void
    {
        $conversation = new CanonicalConversation(
            'Example conversation',
            SourcePlatform::Cursor,
            'conversation-1',
            [],
        );

        Assert::assertSame([], $conversation->metadata);
        Assert::assertSame([], $conversation->sources);
        Assert::assertNull($conversation->canonicalLeafSourceMessageId);
        Assert::assertSame([], $conversation->projectIdentifiers);
    }
}
