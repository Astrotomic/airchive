<?php

namespace Tests\Unit\Enums;

use App\Enums\SourcePlatform;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class SourcePlatformTest extends UnitTestCase
{
    #[DataProvider('platforms')]
    public function test_it_defines_values_and_labels(
        SourcePlatform $platform,
        string $value,
        string $label,
    ): void {
        Assert::assertSame($value, $platform->value);
        Assert::assertSame($label, $platform->label());
    }

    /** @return iterable<string, array{SourcePlatform, string, string}> */
    public static function platforms(): iterable
    {
        yield 'ChatGPT' => [SourcePlatform::ChatGpt, 'chatgpt', 'ChatGPT'];
        yield 'Codex' => [SourcePlatform::Codex, 'codex', 'Codex'];
        yield 'Cursor' => [SourcePlatform::Cursor, 'cursor', 'Cursor'];
    }
}
