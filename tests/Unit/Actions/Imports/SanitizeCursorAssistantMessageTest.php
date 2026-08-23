<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\SanitizeCursorAssistantMessage;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class SanitizeCursorAssistantMessageTest extends TestCase
{
    public function test_removes_redacted_markers_from_assistant_messages(): void
    {
        $raw = "Planning next steps.\n\n[REDACTED]\n\nMore visible text.";

        Assert::assertSame(
            "Planning next steps.\n\nMore visible text.",
            SanitizeCursorAssistantMessage::make()->execute($raw),
        );
    }
}
