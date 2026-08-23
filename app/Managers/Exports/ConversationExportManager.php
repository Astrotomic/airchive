<?php

namespace App\Managers\Exports;

use App\Contracts\ConversationExportDriver;
use App\Enums\ExportFormat;
use App\Managers\Exports\Drivers\HtmlExportDriver;
use App\Managers\Exports\Drivers\JsonExportDriver;
use App\Managers\Exports\Drivers\MarkdownExportDriver;
use App\Models\Conversation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;

final class ConversationExportManager extends Manager
{
    public function export(Conversation $conversation, ExportFormat $format): string
    {
        /** @var ConversationExportDriver $driver */
        $driver = $this->driver($format->value);

        return $driver->export($conversation);
    }

    public function getDefaultDriver(): string
    {
        return Config::string('exports.default', ExportFormat::Markdown->value);
    }

    protected function createMdDriver(): ConversationExportDriver
    {
        return new MarkdownExportDriver;
    }

    protected function createHtmlDriver(): ConversationExportDriver
    {
        return new HtmlExportDriver;
    }

    protected function createJsonDriver(): ConversationExportDriver
    {
        return new JsonExportDriver;
    }
}
