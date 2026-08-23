<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\SanitizeCursorUserMessage;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class SanitizeCursorUserMessageTest extends UnitTestCase
{
    #[DataProvider('messages')]
    public function test_strips_cursor_xml_from_user_messages(string $message, string $expected): void
    {
        Assert::assertSame($expected, SanitizeCursorUserMessage::make()->execute($message));
    }

    /** @return iterable<string, array{string, string}> */
    public static function messages(): iterable
    {
        yield 'timestamp and wrapped query' => [
            "<timestamp>Saturday, Jul 4, 2026</timestamp>\n<user_query>\nBuild a Laravel archive app\n</user_query>",
            'Build a Laravel archive app',
        ];
        yield 'case insensitive multiline query' => ["<USER_QUERY>\nFirst\nSecond\n</USER_QUERY>", "First\nSecond"];
        yield 'orphan query tags' => ['<user_query>Build it', 'Build it'];
        yield 'plain message' => ['  Build it  ', 'Build it'];
        yield 'empty message' => ['', ''];
    }
}
