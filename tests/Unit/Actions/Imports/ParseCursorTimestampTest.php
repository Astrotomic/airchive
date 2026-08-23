<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\ParseCursorTimestamp;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class ParseCursorTimestampTest extends UnitTestCase
{
    public function test_parses_cursor_timestamp_with_timezone_suffix(): void
    {
        $parsed = ParseCursorTimestamp::make()->execute('Saturday, Jul 4, 2026, 10:32 PM (UTC+2)');

        Assert::assertNotNull($parsed);
        Assert::assertSame('2026-07-04 22:32:00', $parsed->format('Y-m-d H:i:s'));
        Assert::assertSame('+02:00', $parsed->format('P'));
    }

    public function test_parses_cursor_timestamp_with_hour_and_minute_timezone_suffix(): void
    {
        $parsed = ParseCursorTimestamp::make()->execute('2026-07-04 22:32 (UTC-05:30)');

        Assert::assertNotNull($parsed);
        Assert::assertSame('-05:30', $parsed->format('P'));
    }

    public function test_parses_cursor_date_only_timestamp(): void
    {
        $parsed = ParseCursorTimestamp::make()->execute('Sunday, Jul 12, 2026');

        Assert::assertNotNull($parsed);
        Assert::assertSame('2026-07-12', $parsed->toDateString());
    }

    public function test_returns_null_for_unparseable_cursor_timestamp(): void
    {
        Assert::assertNull(ParseCursorTimestamp::make()->execute('not a real date (UTC+2)'));
    }

    public function test_returns_null_for_an_empty_timestamp(): void
    {
        Assert::assertNull(ParseCursorTimestamp::make()->execute('  '));
    }
}
