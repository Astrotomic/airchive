<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\SanitizeCursorAssistantMessage;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class SanitizeCursorAssistantMessageTest extends UnitTestCase
{
    #[DataProvider('messages')]
    public function test_removes_redacted_markers_from_assistant_messages(string $message, string $expected): void
    {
        Assert::assertSame($expected, SanitizeCursorAssistantMessage::make()->execute($message));
    }

    /** @return iterable<string, array{string, string}> */
    public static function messages(): iterable
    {
        yield 'standalone marker' => [
            "Planning next steps.\n\n[REDACTED]\n\nMore visible text.",
            "Planning next steps.\n\nMore visible text.",
        ];
        yield 'inline marker' => ['Visible [REDACTED] text', 'Visible  text'];
        yield 'case insensitive marker' => ['[redacted]', ''];
        yield 'collapses excessive blank lines' => ["First\n\n\n\nSecond", "First\n\nSecond"];
        yield 'trims output' => ["  Visible  \n", 'Visible'];
    }
}
