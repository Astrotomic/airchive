<?php

namespace App\Managers\Imports\Drivers;

use App\Actions\Imports\ParseChatGptConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Contracts\ConversationImportDriver;
use App\Enums\ImportFormat;
use App\Models\ImportBatch;
use App\ValueObjects\ImportContext;
use RuntimeException;

final readonly class ChatGptJsonImportDriver implements ConversationImportDriver
{
    public function import(
        ImportBatch $batch,
        string $sourcePath,
        string $checksum,
        ?callable $progress = null,
    ): void {
        $contents = file_get_contents($sourcePath);

        if (! is_string($contents)) {
            throw new RuntimeException('Import file could not be read: '.$sourcePath);
        }

        $context = new ImportContext(
            userId: $batch->user_id,
            filePath: $batch->file_path,
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: $checksum,
            sourceFile: basename($sourcePath),
        );

        foreach (ParseChatGptConversation::make()->execute($context, $contents) as $canonical) {
            WriteCanonicalConversation::make()->execute($context, $canonical);
        }
    }
}
