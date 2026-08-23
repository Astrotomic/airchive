<?php

namespace App\Managers\Imports\Drivers;

use App\Actions\Imports\ParseCursorJsonlConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Contracts\ConversationImportDriver;
use App\Enums\ImportFormat;
use App\Models\ImportBatch;
use App\ValueObjects\ImportContext;
use RuntimeException;

final readonly class CursorJsonlImportDriver implements ConversationImportDriver
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
            sourceFormat: ImportFormat::CursorJsonl,
            rawChecksum: $checksum,
            sourceFile: basename($sourcePath),
            metadata: $this->metadataFromPath($sourcePath),
        );

        $canonical = ParseCursorJsonlConversation::make()->execute($context, $contents);
        WriteCanonicalConversation::make()->execute($context, $canonical);
    }

    /** @return array<string, mixed> */
    private function metadataFromPath(string $sourcePath): array
    {
        $normalized = str_replace('\\', '/', $sourcePath);

        if (preg_match('~/([^/]+)/agent-transcripts/([^/]+)(?:/subagents)?/[^/]+\.jsonl$~i', $normalized, $matches) !== 1) {
            return [];
        }

        $isSubagent = str_contains($normalized, '/subagents/');

        return array_filter([
            'cursor_workspace' => $matches[1],
            'cursor_is_subagent' => $isSubagent,
            'cursor_parent_transcript_id' => $isSubagent ? $matches[2] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
