<?php

namespace Tests\Unit\Enums;

use App\Enums\BlockType;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class BlockTypeTest extends UnitTestCase
{
    public function test_it_defines_supported_block_types(): void
    {
        Assert::assertSame([
            'text',
            'code',
            'reasoning',
            'tool_use',
            'tool_result',
            'image',
            'other',
        ], array_column(BlockType::cases(), 'value'));
    }
}
