<?php

namespace App\Managers\Imports\Drivers;

use App\Actions\Imports\ParseCursorJsonlConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Contracts\ConversationImportDriver;
use App\Enums\AttachmentType;
use App\Enums\ImportFormat;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\Message;
use App\ValueObjects\ImportContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use ZipArchive;

final class CursorExportImportDriver implements ConversationImportDriver
{
    private ?ZipArchive $zip = null;

    /** @var array<string, array{path?: string, size: int|null, modified_at: int|null}> */
    private array $entries = [];

    /** @var array<string, string> */
    private array $workspaceRoots = [];

    private string $exportPath = '';

    public function import(
        ImportBatch $batch,
        string $exportPath,
        string $checksum,
        ?callable $progress = null,
    ): void {
        try {
            $this->openExport($exportPath);
            $this->importExport($batch, $checksum, $progress);
        } finally {
            $this->zip?->close();
            $this->zip = null;
        }
    }

    private function importExport(ImportBatch $batch, string $checksum, ?callable $progress): void
    {
        $transcripts = $this->transcriptEntries();
        natsort($transcripts);
        $artifactMap = $this->artifactPathMap();
        $linkedEntries = [];
        $conversationCount = 0;

        foreach ($transcripts as $entry) {
            $contents = $this->contents($entry);
            $workspace = $this->workspace($entry);
            $context = new ImportContext(
                userId: $batch->user_id,
                filePath: $batch->file_path,
                sourceFormat: ImportFormat::CursorExport,
                rawChecksum: hash('sha256', $contents),
                sourceFile: Str::limit($entry, 255, ''),
                metadata: array_filter([
                    'cursor_workspace' => $workspace,
                    'cursor_export_entry' => $entry,
                    'cursor_export_checksum' => $checksum,
                    'cursor_is_subagent' => str_contains($entry, '/subagents/'),
                    'cursor_parent_transcript_id' => $this->parentTranscriptId($entry),
                    'cursor_export_modified_at' => $this->modifiedAt($entry),
                ], static fn (mixed $value): bool => $value !== null),
            );

            $canonical = ParseCursorJsonlConversation::make()->execute($context, $contents);
            $conversation = WriteCanonicalConversation::make()->execute($context, $canonical);
            $references = $this->referencesBySourceMessageId($contents, array_keys($artifactMap));

            foreach ($references as $sourceMessageId => $paths) {
                $message = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('source_message_id', $sourceMessageId)
                    ->first();

                if ($message === null) {
                    continue;
                }

                foreach ($paths as $path) {
                    $artifactEntry = $artifactMap[$path] ?? null;

                    if ($artifactEntry === null) {
                        continue;
                    }

                    $this->attachArtifact($batch, $conversation, $message, $artifactEntry, $checksum, true);
                    $linkedEntries[$artifactEntry] = true;
                }
            }

            $conversationCount++;

            if ($conversationCount % 100 === 0 && $progress !== null) {
                $progress("Imported {$conversationCount} Cursor conversations…");
            }
        }

        if ($progress !== null) {
            $progress("Imported {$conversationCount} Cursor conversations. Importing workspace artifacts…");
        }

        $artifactCount = count($linkedEntries);

        foreach ($this->artifactEntries() as $entry) {
            if (isset($linkedEntries[$entry])) {
                continue;
            }

            $this->attachArtifact($batch, null, null, $entry, $checksum, false);
            $artifactCount++;
        }

        if ($progress !== null) {
            $progress("Imported {$artifactCount} Cursor workspace artifacts.");
        }
    }

    /**
     * @param  array<int, string>  $knownPaths
     * @return array<string, array<int, string>>
     */
    private function referencesBySourceMessageId(string $contents, array $knownPaths): array
    {
        $known = array_fill_keys($knownPaths, true);
        $workspaces = array_values(array_unique(array_map(
            static fn (string $path): string => Str::before($path, '/'),
            $knownPaths,
        )));

        if ($workspaces === []) {
            return [];
        }

        $workspacePattern = implode('|', array_map(
            static fn (string $workspace): string => preg_quote($workspace, '~'),
            $workspaces,
        ));
        $references = [];
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $messageIndex = 0;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (! is_array($row)) {
                continue;
            }

            $sourceMessageId = (string) ($row['id'] ?? $row['message_id'] ?? $row['uuid'] ?? 'line-'.($messageIndex + 1));
            $strings = $this->stringValues($row['message']['content'] ?? $row['content'] ?? []);

            foreach ($strings as $value) {
                preg_match_all(
                    '~\.cursor/projects/('.$workspacePattern.')/([^\r\n"\'`<>]+)~',
                    str_replace('\\/', '/', $value),
                    $matches,
                    PREG_SET_ORDER,
                );

                foreach ($matches as $match) {
                    $candidate = $match[1].'/'.$match[2];
                    $resolved = $this->resolveKnownPath($candidate, $known);

                    if ($resolved !== null) {
                        $references[$sourceMessageId][$resolved] = $resolved;
                    }
                }
            }

            $messageIndex++;
        }

        return array_map('array_values', $references);
    }

    /** @param array<string, true> $known */
    private function resolveKnownPath(string $candidate, array $known): ?string
    {
        $candidate = trim($candidate);

        if (isset($known[$candidate])) {
            return $candidate;
        }

        while ($candidate !== '' && str_contains($candidate, '/')) {
            $candidate = rtrim($candidate, " \t\n\r\0\x0B`'\".,;:)]}");

            if (isset($known[$candidate])) {
                return $candidate;
            }

            $lastSpace = strrpos($candidate, ' ');

            if ($lastSpace === false) {
                break;
            }

            $candidate = substr($candidate, 0, $lastSpace);
        }

        return null;
    }

    /** @return array<int, string> */
    private function stringValues(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        array_walk_recursive($value, static function (mixed $item) use (&$strings): void {
            if (is_string($item)) {
                $strings[] = $item;
            }
        });

        return $strings;
    }

    private function attachArtifact(
        ImportBatch $batch,
        ?Conversation $conversation,
        ?Message $message,
        string $entry,
        string $exportChecksum,
        bool $referenced,
    ): void {
        $sourceAttachmentId = hash('sha256', $entry);
        $filename = Str::limit(basename($entry), 255, '');
        $mimeType = $this->mimeType($filename);
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $storagePath = sprintf(
            'attachments/%d/cursor/%s/%s%s',
            $batch->user_id,
            $exportChecksum,
            $sourceAttachmentId,
            $extension !== '' ? '.'.$extension : '',
        );

        if (! Storage::exists($storagePath)) {
            $stream = $this->stream($entry);

            try {
                if (! Storage::writeStream($storagePath, $stream)) {
                    throw new RuntimeException("Failed to store Cursor artifact: {$entry}");
                }
            } finally {
                fclose($stream);
            }
        }

        $storedPath = Storage::path($storagePath);
        $fileChecksum = hash_file('sha256', $storedPath);

        if (! is_string($fileChecksum)) {
            throw new RuntimeException("Failed to checksum stored Cursor artifact: {$entry}");
        }

        if ($referenced) {
            Attachment::query()
                ->where('user_id', $batch->user_id)
                ->where('source_platform', SourcePlatform::Cursor->value)
                ->where('source_attachment_id', $sourceAttachmentId)
                ->whereNull('conversation_id')
                ->delete();
        }

        Attachment::query()->updateOrCreate([
            'user_id' => $batch->user_id,
            'source_platform' => SourcePlatform::Cursor->value,
            'source_attachment_id' => $sourceAttachmentId,
            'conversation_id' => $conversation?->id,
        ], [
            'message_id' => $message?->id,
            'attachment_type' => $this->attachmentType($mimeType, $entry),
            'filename' => $filename,
            'mime_type' => $mimeType,
            'byte_size' => $this->size($entry),
            'checksum' => $fileChecksum,
            'storage_path' => $storagePath,
            'source_ref' => array_filter([
                'cursor_export_entry' => $entry,
                'cursor_workspace' => $this->workspace($entry),
                'cursor_artifact_kind' => $this->artifactKind($entry)->value,
                'cursor_referenced_by_message' => $referenced,
                'cursor_modified_at' => $this->modifiedAt($entry),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    public function __destruct()
    {
        $this->zip?->close();
    }

    private function openExport(string $path): void
    {
        $this->zip?->close();
        $this->zip = null;
        $this->entries = [];
        $this->workspaceRoots = [];
        $this->exportPath = $path;

        if (is_dir($path)) {
            $this->indexDirectory();
        } else {
            $this->indexZip();
        }

        $this->indexWorkspaces();

        if ($this->transcriptEntries() === []) {
            throw new RuntimeException('Cursor export does not contain any agent transcript JSONL files.');
        }
    }

    /** @return array<int, string> */
    private function transcriptEntries(): array
    {
        return array_values(array_filter(
            array_keys($this->entries),
            static fn (string $entry): bool => preg_match('~(?:^|/)agent-transcripts/.+\.jsonl$~i', $entry) === 1
                && ! str_starts_with(strtolower($entry), '__macosx/')
                && ! str_starts_with(basename($entry), '._'),
        ));
    }

    /** @return array<int, string> */
    private function artifactEntries(): array
    {
        return array_values(array_filter(
            array_keys($this->entries),
            fn (string $entry): bool => $this->isUsefulArtifact($entry),
        ));
    }

    private function contents(string $entry): string
    {
        if (! isset($this->entries[$entry])) {
            throw new RuntimeException("Cursor export entry does not exist: {$entry}");
        }

        $contents = isset($this->entries[$entry]['path'])
            ? file_get_contents($this->entries[$entry]['path'])
            : $this->zip?->getFromName($entry);

        if (! is_string($contents)) {
            throw new RuntimeException("Failed to read Cursor export entry: {$entry}");
        }

        return $contents;
    }

    /** @return resource */
    private function stream(string $entry)
    {
        if (! isset($this->entries[$entry])) {
            throw new RuntimeException("Cursor export entry does not exist: {$entry}");
        }

        $stream = isset($this->entries[$entry]['path'])
            ? fopen($this->entries[$entry]['path'], 'rb')
            : $this->zip?->getStream($entry);

        if (! is_resource($stream)) {
            throw new RuntimeException("Failed to stream Cursor export entry: {$entry}");
        }

        return $stream;
    }

    private function size(string $entry): ?int
    {
        return $this->entries[$entry]['size'] ?? null;
    }

    private function modifiedAt(string $entry): ?int
    {
        return $this->entries[$entry]['modified_at'] ?? null;
    }

    private function workspace(string $entry): ?string
    {
        foreach ($this->workspaceRoots as $workspace => $root) {
            if ($entry === $root || str_starts_with($entry, $root.'/')) {
                return $workspace;
            }
        }

        $parts = explode('/', $entry);

        foreach (['agent-transcripts', 'agent-tools', 'canvases', 'terminals', 'mcps'] as $directory) {
            $position = array_search($directory, $parts, true);

            if (is_int($position) && $position > 0) {
                return $parts[$position - 1];
            }
        }

        return count($parts) > 1 ? $parts[0] : null;
    }

    /** @return array<string, string> */
    private function artifactPathMap(): array
    {
        $map = [];

        foreach ($this->artifactEntries() as $entry) {
            $workspace = $this->workspace($entry);

            if ($workspace === null) {
                continue;
            }

            $root = $this->workspaceRoots[$workspace] ?? $workspace;

            if (! str_starts_with($entry, $root.'/')) {
                continue;
            }

            $relative = substr($entry, strlen($root) + 1);
            $map[$workspace.'/'.$relative] = $entry;
        }

        return $map;
    }

    private function indexDirectory(): void
    {
        $root = rtrim((string) realpath($this->exportPath), DIRECTORY_SEPARATOR);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->isLink()) {
                continue;
            }

            $absolute = $file->getPathname();
            $entry = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
            $this->entries[$entry] = [
                'path' => $absolute,
                'size' => $file->getSize(),
                'modified_at' => $file->getMTime(),
            ];
        }
    }

    private function indexZip(): void
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($this->exportPath) !== true) {
            throw new RuntimeException('Failed to open Cursor export ZIP archive.');
        }

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $stats = $this->zip->statIndex($index);
            $entry = str_replace('\\', '/', (string) ($stats['name'] ?? ''));

            if ($entry === '' || str_ends_with($entry, '/') || ! $this->isSafeEntry($entry)) {
                continue;
            }

            $this->entries[$entry] = [
                'size' => isset($stats['size']) ? (int) $stats['size'] : null,
                'modified_at' => isset($stats['mtime']) ? (int) $stats['mtime'] : null,
            ];
        }
    }

    private function indexWorkspaces(): void
    {
        foreach ($this->transcriptEntries() as $entry) {
            $parts = explode('/', $entry);
            $position = array_search('agent-transcripts', $parts, true);

            if (! is_int($position) || $position < 1) {
                continue;
            }

            $workspace = $parts[$position - 1];
            $this->workspaceRoots[$workspace] = implode('/', array_slice($parts, 0, $position));
        }
    }

    private function isSafeEntry(string $entry): bool
    {
        return ! str_starts_with($entry, '/')
            && ! in_array('..', explode('/', $entry), true);
    }

    private function isUsefulArtifact(string $entry): bool
    {
        $lower = strtolower('/'.$entry);

        if (preg_match('~/(?:__macosx|mcps|node_modules|\.git|agent-transcripts)(?:/|$)~', $lower) === 1) {
            return false;
        }

        if (str_ends_with($lower, '/.ds_store') || str_ends_with($lower, '/mcp-cache.json')) {
            return false;
        }

        return true;
    }

    private function mimeType(string $filename): string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = $extension !== '' ? MimeTypes::getDefault()->getMimeTypes($extension) : [];

        return $mimeTypes[0] ?? 'application/octet-stream';
    }

    private function attachmentType(string $mimeType, string $entry): AttachmentType
    {
        if (str_starts_with($mimeType, 'image/')) {
            return AttachmentType::Image;
        }

        return $this->artifactKind($entry);
    }

    private function artifactKind(string $entry): AttachmentType
    {
        return match (true) {
            str_contains($entry, '/canvases/') => AttachmentType::Canvas,
            str_contains($entry, '/agent-tools/') => AttachmentType::AgentTool,
            str_contains($entry, '/terminals/') => AttachmentType::Terminal,
            default => AttachmentType::File,
        };
    }

    private function parentTranscriptId(string $entry): ?string
    {
        if (preg_match('~/agent-transcripts/([^/]+)/subagents/~', $entry, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
