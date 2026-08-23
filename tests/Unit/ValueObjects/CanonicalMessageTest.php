<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\Enums\MessageRole;
use App\ValueObjects\CanonicalAttachment;
use App\ValueObjects\CanonicalContentBlock;
use App\ValueObjects\CanonicalMessage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class CanonicalMessageTest extends UnitTestCase
{
    public function test_it_stores_message_data(): void
    {
        $createdAt = CarbonImmutable::parse('2026-08-23T12:34:56+00:00');
        $block = new CanonicalContentBlock(0, BlockType::Text, 'Hello');
        $attachment = new CanonicalAttachment(AttachmentType::File);
        $message = new CanonicalMessage(
            sourceMessageId: 'message-2',
            parentSourceMessageId: 'message-1',
            role: MessageRole::Assistant,
            actorName: 'assistant',
            createdAt: $createdAt,
            isOnCanonicalPath: true,
            isHidden: false,
            blocks: [$block],
            metadata: ['model' => 'gpt-5'],
            attachments: [$attachment],
        );

        Assert::assertSame('message-2', $message->sourceMessageId);
        Assert::assertSame('message-1', $message->parentSourceMessageId);
        Assert::assertSame(MessageRole::Assistant, $message->role);
        Assert::assertSame('assistant', $message->actorName);
        Assert::assertSame($createdAt, $message->createdAt);
        Assert::assertTrue($message->isOnCanonicalPath);
        Assert::assertFalse($message->isHidden);
        Assert::assertSame([$block], $message->blocks);
        Assert::assertSame(['model' => 'gpt-5'], $message->metadata);
        Assert::assertSame([$attachment], $message->attachments);
    }

    public function test_optional_message_collections_have_empty_defaults(): void
    {
        $message = new CanonicalMessage(
            sourceMessageId: 'message-1',
            parentSourceMessageId: null,
            role: MessageRole::User,
            actorName: null,
            createdAt: null,
            isOnCanonicalPath: false,
            isHidden: true,
            blocks: [],
        );

        Assert::assertNull($message->parentSourceMessageId);
        Assert::assertNull($message->actorName);
        Assert::assertNull($message->createdAt);
        Assert::assertSame([], $message->metadata);
        Assert::assertSame([], $message->attachments);
    }
}
