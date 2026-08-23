<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\ExtractCursorTimestamp;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ExtractCursorTimestampTest extends TestCase
{
    public function test_extracts_timestamp_from_cursor_xml(): void
    {
        $raw = "<timestamp>Saturday, Jul 4, 2026</timestamp>\n<user_query>\nBuild a Laravel archive app\n</user_query>";

        Assert::assertSame('Saturday, Jul 4, 2026', ExtractCursorTimestamp::make()->execute($raw));
    }
}
