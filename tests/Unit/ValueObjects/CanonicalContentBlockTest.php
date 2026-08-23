<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\ValueObjects\CanonicalAttachment;
use App\ValueObjects\CanonicalContentBlock;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class CanonicalContentBlockTest extends UnitTestCase
{
    public function test_it_stores_content_block_data(): void
    {
        $attachment = new CanonicalAttachment(AttachmentType::Image);
        $block = new CanonicalContentBlock(
            position: 2,
            blockType: BlockType::Code,
            textContent: 'echo "hello";',
            structuredContent: ['output' => 'hello'],
            language: 'php',
            metadata: ['collapsed' => true],
            attachments: [$attachment],
        );

        Assert::assertSame(2, $block->position);
        Assert::assertSame(BlockType::Code, $block->blockType);
        Assert::assertSame('echo "hello";', $block->textContent);
        Assert::assertSame(['output' => 'hello'], $block->structuredContent);
        Assert::assertSame('php', $block->language);
        Assert::assertSame(['collapsed' => true], $block->metadata);
        Assert::assertSame([$attachment], $block->attachments);
    }

    public function test_optional_content_block_data_has_empty_defaults(): void
    {
        $block = new CanonicalContentBlock(0, BlockType::Text);

        Assert::assertNull($block->textContent);
        Assert::assertNull($block->structuredContent);
        Assert::assertNull($block->language);
        Assert::assertSame([], $block->metadata);
        Assert::assertSame([], $block->attachments);
    }
}
