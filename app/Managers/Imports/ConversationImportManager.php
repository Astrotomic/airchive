<?php

namespace App\Managers\Imports;

use App\Contracts\ConversationImportDriver;
use App\Enums\ImportFormat;
use App\Managers\Imports\Drivers\ChatGptJsonImportDriver;
use App\Managers\Imports\Drivers\ChatGptZipImportDriver;
use App\Managers\Imports\Drivers\CursorExportImportDriver;
use App\Managers\Imports\Drivers\CursorJsonlImportDriver;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;
use LogicException;

final class ConversationImportManager extends Manager
{
    public function import(
        ImportBatch $batch,
        string $sourcePath,
        string $checksum,
        ?callable $progress = null,
    ): void {
        $format = $batch->detected_format;

        if ($format === null) {
            throw new LogicException('The import format must be detected before selecting a driver.');
        }

        /** @var ConversationImportDriver $driver */
        $driver = $this->driver($format);
        $driver->import($batch, $sourcePath, $checksum, $progress);
    }

    public function getDefaultDriver(): string
    {
        return Config::string('imports.default', ImportFormat::ChatGptJson->value);
    }

    protected function createChatgptJsonDriver(): ConversationImportDriver
    {
        return $this->container->make(ChatGptJsonImportDriver::class);
    }

    protected function createChatgptZipDriver(): ConversationImportDriver
    {
        return $this->container->make(ChatGptZipImportDriver::class);
    }

    protected function createCursorJsonlDriver(): ConversationImportDriver
    {
        return $this->container->make(CursorJsonlImportDriver::class);
    }

    protected function createCursorExportDriver(): ConversationImportDriver
    {
        return $this->container->make(CursorExportImportDriver::class);
    }
}
