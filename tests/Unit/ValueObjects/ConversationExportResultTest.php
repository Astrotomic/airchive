<?php

namespace Tests\Unit\ValueObjects;

use App\ValueObjects\ConversationExportResult;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class ConversationExportResultTest extends UnitTestCase
{
    public function test_it_stores_export_counts_and_unavailable_files(): void
    {
        $result = new ConversationExportResult(
            chatCount: 3,
            fileCount: 7,
            unavailableFiles: ['attachments/missing.txt'],
        );

        Assert::assertSame(3, $result->chatCount);
        Assert::assertSame(7, $result->fileCount);
        Assert::assertSame(['attachments/missing.txt'], $result->unavailableFiles);
    }

    public function test_unavailable_files_default_to_an_empty_list(): void
    {
        $result = new ConversationExportResult(0, 0);

        Assert::assertSame([], $result->unavailableFiles);
    }
}
