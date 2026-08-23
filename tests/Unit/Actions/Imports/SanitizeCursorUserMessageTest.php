<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\SanitizeCursorUserMessage;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class SanitizeCursorUserMessageTest extends TestCase
{
    public function test_strips_cursor_xml_from_user_messages(): void
    {
        $raw = "<timestamp>Saturday, Jul 4, 2026</timestamp>\n<user_query>\nBuild a Laravel archive app\n</user_query>";

        Assert::assertSame('Build a Laravel archive app', SanitizeCursorUserMessage::make()->execute($raw));
    }
}
