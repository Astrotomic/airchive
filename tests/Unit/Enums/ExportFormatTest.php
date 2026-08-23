<?php

namespace Tests\Unit\Enums;

use App\Enums\ExportFormat;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ExportFormatTest extends UnitTestCase
{
    #[DataProvider('parsedValues')]
    public function test_it_parses_export_formats(string $value, ?ExportFormat $expected): void
    {
        Assert::assertSame($expected, ExportFormat::parse($value));
    }

    /** @return iterable<string, array{string, ExportFormat|null}> */
    public static function parsedValues(): iterable
    {
        yield 'Markdown name' => ['markdown', ExportFormat::Markdown];
        yield 'Markdown extension' => ['md', ExportFormat::Markdown];
        yield 'normalized HTML extension' => [' HTML ', ExportFormat::Html];
        yield 'JSON extension' => ['json', ExportFormat::Json];
        yield 'unsupported format' => ['xml', null];
    }

    #[DataProvider('formats')]
    public function test_it_defines_values_labels_and_content_types(
        ExportFormat $format,
        string $value,
        string $label,
        string $contentType,
    ): void {
        Assert::assertSame($value, $format->value);
        Assert::assertSame($label, $format->label());
        Assert::assertSame($contentType, $format->contentType());
    }

    /** @return iterable<string, array{ExportFormat, string, string, string}> */
    public static function formats(): iterable
    {
        yield 'Markdown' => [
            ExportFormat::Markdown,
            'md',
            'Markdown',
            'text/markdown; charset=UTF-8',
        ];
        yield 'HTML' => [
            ExportFormat::Html,
            'html',
            'HTML',
            'text/html; charset=UTF-8',
        ];
        yield 'JSON' => [
            ExportFormat::Json,
            'json',
            'JSON',
            'application/json; charset=UTF-8',
        ];
    }
}
