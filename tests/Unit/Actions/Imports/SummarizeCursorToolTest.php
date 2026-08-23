<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\SummarizeCursorTool;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class SummarizeCursorToolTest extends UnitTestCase
{
    #[DataProvider('tools')]
    public function test_builds_tool_summaries(string $name, ?array $input, string $expected): void
    {
        Assert::assertSame($expected, SummarizeCursorTool::make()->execute($name, $input));
    }

    /** @return iterable<string, array{string, array<string, mixed>|null, string}> */
    public static function tools(): iterable
    {
        yield 'missing input' => ['Read', null, 'Read'];
        yield 'empty input' => ['Read', [], 'Read'];
        yield 'Read path' => ['Read', ['path' => '/Users/test/example.json'], 'Read /Users/test/example.json'];
        yield 'Read target file fallback' => ['Read', ['target_file' => 'fallback.php'], 'Read fallback.php'];
        yield 'Read default' => ['Read', ['other' => true], 'Read file'];
        yield 'Glob' => ['Glob', ['glob_pattern' => '*.php'], 'Glob *.php'];
        yield 'Glob default' => ['Glob', ['other' => true], 'Glob **/*'];
        yield 'Grep' => ['Grep', ['pattern' => 'schema'], 'Grep schema'];
        yield 'Grep scalar conversion' => ['Grep', ['pattern' => 42], 'Grep 42'];
        yield 'Grep non-scalar conversion' => ['Grep', ['pattern' => ['schema']], 'Grep '];
        yield 'Shell description' => ['Shell', ['description' => 'List files', 'command' => 'ls'], 'Shell List files'];
        yield 'Shell command fallback' => ['Shell', ['command' => 'ls -la'], 'Shell ls -la'];
        yield 'Shell default' => ['Shell', ['other' => true], 'Shell command'];
        yield 'Shell truncated command' => ['Shell', ['command' => str_repeat('x', 90)], 'Shell '.str_repeat('x', 80).'…'];
        yield 'WebSearch' => ['WebSearch', ['search_term' => 'Laravel'], 'Search Laravel'];
        yield 'WebFetch' => ['WebFetch', ['url' => 'https://example.com'], 'Fetch https://example.com'];
        yield 'Task' => ['Task', ['description' => 'Do it'], 'Task'];
        yield 'CreatePlan' => ['CreatePlan', ['steps' => []], 'CreatePlan'];
        yield 'AskQuestion' => ['AskQuestion', ['question' => 'Why?'], 'AskQuestion'];
        yield 'SwitchMode' => ['SwitchMode', ['mode' => 'agent'], 'SwitchMode'];
        yield 'unknown tool' => ['Custom', ['path' => '/tmp/file'], 'Custom {"path":"/tmp/file"}'];
        yield 'unknown tool truncation' => [
            'Custom',
            ['value' => str_repeat('x', 100)],
            'Custom {"value":"'.str_repeat('x', 70).'…',
        ];
        yield 'unknown tool invalid JSON' => ['Custom', ['value' => INF], 'Custom '];
    }
}
