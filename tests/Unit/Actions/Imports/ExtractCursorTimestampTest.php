<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\ExtractCursorTimestamp;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ExtractCursorTimestampTest extends UnitTestCase
{
    #[DataProvider('timestamps')]
    public function test_extracts_timestamp_from_cursor_xml(string $text, ?string $expected): void
    {
        Assert::assertSame($expected, ExtractCursorTimestamp::make()->execute($text));
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function timestamps(): iterable
    {
        yield 'timestamp' => [
            "<timestamp>Saturday, Jul 4, 2026</timestamp>\n<user_query>Build it</user_query>",
            'Saturday, Jul 4, 2026',
        ];
        yield 'multiline and case-insensitive' => ["<TIMESTAMP>\n  2026-08-23 12:00\n</TIMESTAMP>", '2026-08-23 12:00'];
        yield 'empty timestamp' => ['<timestamp> </timestamp>', null];
        yield 'missing timestamp' => ['Build it', null];
    }
}
