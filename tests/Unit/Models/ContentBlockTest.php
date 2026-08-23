<?php

namespace Tests\Unit\Models;

use App\Enums\BlockType;
use App\Models\ContentBlock;
use Astrotomic\PhpunitAssertions\PathAssertions;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class ContentBlockTest extends TestCase
{
    public function test_it_describes_tool_blocks(): void
    {
        $block = new ContentBlock([
            'block_type' => BlockType::ToolUse,
            'structured_content' => [
                'name' => 'Read',
                'input' => ['path' => '/tmp/example.json'],
            ],
            'metadata' => [],
        ]);
        $summary = $block->toolSummary();

        Assert::assertSame('Read', $block->toolName());
        PathAssertions::assertExtension('json', substr($summary, strlen('Read ')));
        Assert::assertSame('Read /tmp/example.json', $summary);
        Assert::assertTrue($block->isToolBlock());
        Assert::assertTrue($block->collapsedByDefault());
    }

    public function test_metadata_can_override_tool_name_and_collapse_behavior(): void
    {
        $block = new ContentBlock([
            'block_type' => BlockType::ToolResult,
            'structured_content' => ['name' => 'Read'],
            'metadata' => [
                'tool_name' => 'Grep',
                'collapsed_by_default' => false,
            ],
        ]);

        Assert::assertSame('Grep', $block->toolName());
        Assert::assertFalse($block->collapsedByDefault());
    }

    public function test_reasoning_blocks_are_always_collapsed_by_default(): void
    {
        $block = new ContentBlock([
            'block_type' => BlockType::Reasoning,
            'metadata' => ['collapsed_by_default' => false],
        ]);

        Assert::assertFalse($block->isToolBlock());
        Assert::assertTrue($block->collapsedByDefault());
    }
}
