<?php

namespace Tests\Unit\Enums;

use App\Enums\ImportFormat;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ImportFormatTest extends UnitTestCase
{
    #[DataProvider('formats')]
    public function test_it_defines_values_and_labels(
        ImportFormat $format,
        string $value,
        string $label,
    ): void {
        Assert::assertSame($value, $format->value);
        Assert::assertSame($label, $format->label());
    }

    /** @return iterable<string, array{ImportFormat, string, string}> */
    public static function formats(): iterable
    {
        yield 'ChatGPT JSON' => [ImportFormat::ChatGptJson, 'chatgpt_json', 'ChatGPT JSON'];
        yield 'ChatGPT ZIP' => [ImportFormat::ChatGptZip, 'chatgpt_zip', 'ChatGPT ZIP'];
        yield 'Cursor JSONL' => [ImportFormat::CursorJsonl, 'cursor_jsonl', 'Cursor JSONL'];
        yield 'Cursor export' => [ImportFormat::CursorExport, 'cursor_export', 'Cursor export'];
    }
}
