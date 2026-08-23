<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\ImportFormat;
use App\ValueObjects\ImportContext;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class ImportContextTest extends UnitTestCase
{
    public function test_it_stores_import_context_data(): void
    {
        $context = new ImportContext(
            userId: 42,
            filePath: 'imports/export.zip',
            sourceFormat: ImportFormat::ChatGptZip,
            rawChecksum: 'checksum',
            sourceFile: 'export.zip',
            metadata: ['batch_id' => 123],
        );

        Assert::assertSame(42, $context->userId);
        Assert::assertSame('imports/export.zip', $context->filePath);
        Assert::assertSame(ImportFormat::ChatGptZip, $context->sourceFormat);
        Assert::assertSame('checksum', $context->rawChecksum);
        Assert::assertSame('export.zip', $context->sourceFile);
        Assert::assertSame(['batch_id' => 123], $context->metadata);
    }

    public function test_optional_import_context_data_has_empty_defaults(): void
    {
        $context = new ImportContext(42, 'imports/export.jsonl', ImportFormat::CursorJsonl, 'checksum');

        Assert::assertNull($context->sourceFile);
        Assert::assertSame([], $context->metadata);
    }
}
