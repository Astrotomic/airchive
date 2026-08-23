<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\DetectImportFormat;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DetectImportFormatTest extends TestCase
{
    #[Test]
    public function it_reports_truncated_json_as_invalid_or_incomplete(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'airchive_detect_');

        Assert::assertNotFalse($path);
        file_put_contents($path, '{"mapping":{"message":{"author');

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage(
                'The uploaded JSON file is invalid or incomplete. Download or export it again, then retry.'
            );

            DetectImportFormat::make()->execute($path, 'truncated-conversation.json');
        } finally {
            unlink($path);
        }
    }
}
