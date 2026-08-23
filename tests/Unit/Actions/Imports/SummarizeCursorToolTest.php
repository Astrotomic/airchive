<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\SummarizeCursorTool;
use Astrotomic\PhpunitAssertions\PathAssertions;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class SummarizeCursorToolTest extends TestCase
{
    public function test_builds_tool_summaries(): void
    {
        $summary = SummarizeCursorTool::make()->execute('Read', ['path' => '/Users/test/example.json']);

        PathAssertions::assertExtension('json', substr($summary, strlen('Read ')));
        Assert::assertSame('Read /Users/test/example.json', $summary);
        Assert::assertSame(
            'Grep schema',
            SummarizeCursorTool::make()->execute('Grep', ['pattern' => 'schema']),
        );
    }
}
