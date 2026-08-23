<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\ImportFormat;
use App\ValueObjects\CanonicalConversationSource;
use App\ValueObjects\ImportContext;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class CanonicalConversationSourceTest extends UnitTestCase
{
    #[DataProvider('sourceFiles')]
    public function test_it_builds_a_source_from_import_context(
        ?string $contextSourceFile,
        ?string $defaultSourceFile,
        string $expectedSourceFile,
    ): void {
        $context = new ImportContext(
            userId: 42,
            filePath: 'imports/export/conversations.json',
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: 'checksum',
            sourceFile: $contextSourceFile,
        );

        $source = CanonicalConversationSource::fromImportContext($context, $defaultSourceFile);

        Assert::assertSame($expectedSourceFile, $source->sourceFile);
        Assert::assertSame(ImportFormat::ChatGptJson, $source->sourceFormat);
        Assert::assertSame('checksum', $source->rawChecksum);
        Assert::assertSame('imports/export/conversations.json', $source->rawStoragePath);
    }

    /** @return iterable<string, array{string|null, string|null, string}> */
    public static function sourceFiles(): iterable
    {
        yield 'context source file takes precedence' => ['uploaded.json', 'default.json', 'uploaded.json'];
        yield 'provided default is the second choice' => [null, 'default.json', 'default.json'];
        yield 'file path basename is the final fallback' => [null, null, 'conversations.json'];
    }
}
