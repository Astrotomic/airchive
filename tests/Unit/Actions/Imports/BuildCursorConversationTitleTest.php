<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\BuildCursorConversationTitle;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class BuildCursorConversationTitleTest extends UnitTestCase
{
    #[DataProvider('messages')]
    public function test_builds_title_from_user_message(string $message, string $expected): void
    {
        Assert::assertSame($expected, BuildCursorConversationTitle::make()->execute($message));
    }

    /** @return iterable<string, array{string, string}> */
    public static function messages(): iterable
    {
        yield 'sanitized query' => [
            "<user_query>\nBuild a Laravel archive app\n</user_query>",
            'Build a Laravel archive app',
        ];
        yield 'first line' => ["First line\nSecond line", 'First line'];
        yield 'empty message fallback' => [" \n ", ''];
        yield 'limited title' => [str_repeat('a', 100), str_repeat('a', 80).'...'];
    }
}
