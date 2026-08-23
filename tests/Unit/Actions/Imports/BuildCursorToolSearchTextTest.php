<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\BuildCursorToolSearchText;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class BuildCursorToolSearchTextTest extends UnitTestCase
{
    public function test_builds_searchable_tool_text_for_array_input(): void
    {
        Assert::assertSame(
            'Read /Users/test/example.json {"path":"/Users/test/example.json"}',
            BuildCursorToolSearchText::make()->execute('Read', ['path' => '/Users/test/example.json']),
        );
    }

    public function test_returns_only_the_summary_for_non_array_input(): void
    {
        Assert::assertSame('Read', BuildCursorToolSearchText::make()->execute('Read', 'file.txt'));
        Assert::assertSame('Read', BuildCursorToolSearchText::make()->execute('Read', null));
    }

    public function test_tolerates_input_that_cannot_be_json_encoded(): void
    {
        Assert::assertSame(
            'Task ',
            BuildCursorToolSearchText::make()->execute('Task', ['invalid' => INF]),
        );
    }
}
