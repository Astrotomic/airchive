<?php

namespace Tests\Unit\Enums;

use App\Enums\ImportBatchStatus;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ImportBatchStatusTest extends UnitTestCase
{
    #[DataProvider('statuses')]
    public function test_it_defines_values_and_labels(
        ImportBatchStatus $status,
        string $value,
        string $label,
    ): void {
        Assert::assertSame($value, $status->value);
        Assert::assertSame($label, $status->label());
    }

    /** @return iterable<string, array{ImportBatchStatus, string, string}> */
    public static function statuses(): iterable
    {
        yield 'pending' => [ImportBatchStatus::Pending, 'pending', 'Pending'];
        yield 'processing' => [ImportBatchStatus::Processing, 'processing', 'Processing'];
        yield 'completed' => [ImportBatchStatus::Completed, 'completed', 'Completed'];
        yield 'failed' => [ImportBatchStatus::Failed, 'failed', 'Failed'];
    }
}
