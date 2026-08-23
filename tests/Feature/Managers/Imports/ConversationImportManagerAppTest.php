<?php

namespace Tests\Feature\Managers\Imports;

use App\Contracts\ConversationImportDriver;
use App\Enums\ImportFormat;
use App\Managers\Imports\ConversationImportManager;
use App\Managers\Imports\Drivers\ChatGptJsonImportDriver;
use App\Managers\Imports\Drivers\ChatGptZipImportDriver;
use App\Managers\Imports\Drivers\CursorExportImportDriver;
use App\Managers\Imports\Drivers\CursorJsonlImportDriver;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ConversationImportManagerAppTest extends AppTestCase
{
    public function test_it_resolves_a_driver_for_each_import_format(): void
    {
        $imports = app(ConversationImportManager::class);

        Assert::assertInstanceOf(ChatGptJsonImportDriver::class, $imports->driver(ImportFormat::ChatGptJson));
        Assert::assertInstanceOf(ChatGptZipImportDriver::class, $imports->driver(ImportFormat::ChatGptZip));
        Assert::assertInstanceOf(CursorJsonlImportDriver::class, $imports->driver(ImportFormat::CursorJsonl));
        Assert::assertInstanceOf(CursorExportImportDriver::class, $imports->driver(ImportFormat::CursorExport));
    }

    public function test_each_import_format_has_a_contract_backed_driver(): void
    {
        $imports = app(ConversationImportManager::class);

        foreach (ImportFormat::cases() as $format) {
            Assert::assertInstanceOf(ConversationImportDriver::class, $imports->driver($format));
        }
    }
}
