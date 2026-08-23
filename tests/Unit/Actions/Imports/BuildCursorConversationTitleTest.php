<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\BuildCursorConversationTitle;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class BuildCursorConversationTitleTest extends TestCase
{
    public function test_builds_title_from_user_message(): void
    {
        Assert::assertSame(
            'Build a Laravel archive app',
            BuildCursorConversationTitle::make()->execute("<user_query>\nBuild a Laravel archive app\n</user_query>"),
        );
    }
}
