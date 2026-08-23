<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\BuildCursorToolSearchText;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class BuildCursorToolSearchTextTest extends TestCase
{
    public function test_builds_searchable_tool_text(): void
    {
        Assert::assertSame(
            'Read /Users/test/example.json {"path":"/Users/test/example.json"}',
            BuildCursorToolSearchText::make()->execute('Read', ['path' => '/Users/test/example.json']),
        );
    }
}
