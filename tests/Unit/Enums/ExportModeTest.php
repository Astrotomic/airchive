<?php

namespace Tests\Unit\Enums;

use App\Enums\ExportMode;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ExportModeTest extends UnitTestCase
{
    #[DataProvider('modes')]
    public function test_it_defines_export_behavior(
        ExportMode $mode,
        string $value,
        string $label,
        bool $includesChats,
        bool $includesFiles,
    ): void {
        Assert::assertSame($value, $mode->value);
        Assert::assertSame($label, $mode->label());
        Assert::assertSame($includesChats, $mode->includesChats());
        Assert::assertSame($includesFiles, $mode->includesFiles());
    }

    /** @return iterable<string, array{ExportMode, string, string, bool, bool}> */
    public static function modes(): iterable
    {
        yield 'chats and files' => [
            ExportMode::ChatsAndFiles,
            'chats_and_files',
            'Chats and attached files',
            true,
            true,
        ];
        yield 'chats only' => [
            ExportMode::ChatsOnly,
            'chats_only',
            'Chats only',
            true,
            false,
        ];
        yield 'files only' => [
            ExportMode::FilesOnly,
            'files_only',
            'Attached files only',
            false,
            true,
        ];
    }
}
