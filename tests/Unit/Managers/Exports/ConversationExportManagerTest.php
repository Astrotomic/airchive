<?php

namespace Tests\Unit\Managers\Exports;

use App\Contracts\ConversationExportDriver;
use App\Enums\ExportFormat;
use App\Managers\Exports\ConversationExportManager;
use App\Managers\Exports\Drivers\HtmlExportDriver;
use App\Managers\Exports\Drivers\JsonExportDriver;
use App\Managers\Exports\Drivers\MarkdownExportDriver;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ConversationExportManagerTest extends TestCase
{
    public function test_it_resolves_a_driver_for_each_export_format(): void
    {
        $exports = app(ConversationExportManager::class);

        Assert::assertInstanceOf(MarkdownExportDriver::class, $exports->driver(ExportFormat::Markdown->value));
        Assert::assertInstanceOf(HtmlExportDriver::class, $exports->driver(ExportFormat::Html->value));
        Assert::assertInstanceOf(JsonExportDriver::class, $exports->driver(ExportFormat::Json->value));
    }

    public function test_each_driver_implements_the_export_contract(): void
    {
        $exports = app(ConversationExportManager::class);

        foreach (ExportFormat::cases() as $format) {
            Assert::assertInstanceOf(ConversationExportDriver::class, $exports->driver($format->value));
        }
    }
}
