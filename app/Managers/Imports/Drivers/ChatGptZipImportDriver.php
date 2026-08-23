<?php

namespace App\Managers\Imports\Drivers;

use App\Actions\Imports\ParseChatGptConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Actions\Projects\ExtractProjectIdentifiers;
use App\Contracts\ConversationImportDriver;
use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\Enums\ImportFormat;
use App\Enums\MessageRole;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\Message;
use App\ValueObjects\CanonicalAttachment;
use App\ValueObjects\CanonicalContentBlock;
use App\ValueObjects\CanonicalConversation;
use App\ValueObjects\CanonicalConversationSource;
use App\ValueObjects\CanonicalMessage;
use App\ValueObjects\ImportContext;
use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;
use ZipArchive;

final class ChatGptZipImportDriver implements ConversationImportDriver
{
    private ZipArchive $zip;

    /** @var array<int, string> */
    private array $entryNames = [];

    /** @var array<string, string> */
    private array $assetEntriesBySourceId = [];

    /** @var array<string, string> */
    private array $assetFilenamesBySourceId = [];

    /** @var array<string, array{storage_path: string, checksum: string, byte_size: int|null}> */
    private array $storedAssets = [];

    public function import(
        ImportBatch $batch,
        string $archivePath,
        string $checksum,
        ?callable $progress = null,
    ): void {
        try {
            $this->openArchive($archivePath);
            $this->importArchive($batch, $checksum, $progress);
        } finally {
            if (isset($this->zip)) {
                $this->zip->close();
                unset($this->zip);
            }
        }
    }

    private function importArchive(ImportBatch $batch, string $checksum, ?callable $progress): void
    {
        $conversationCount = 0;

        foreach ($this->conversations() as $item) {
            $context = new ImportContext(
                userId: $batch->user_id,
                filePath: $batch->file_path,
                sourceFormat: ImportFormat::ChatGptZip,
                rawChecksum: $checksum,
                sourceFile: $item['source_file'],
            );

            foreach (ParseChatGptConversation::make()->execute($context, $item['conversation']) as $canonical) {
                $conversation = WriteCanonicalConversation::make()->execute($context, $canonical);

                $this->storeConversationAttachments($conversation, $checksum);

                $conversationCount++;

                if ($conversationCount % 100 === 0 && $progress !== null) {
                    $progress("Imported {$conversationCount} ChatGPT conversations…");
                }

                unset($canonical, $conversation);
            }
        }

        if ($progress !== null) {
            $progress("Imported {$conversationCount} ChatGPT conversations.");
        }

        $this->applyConversationSupplementals($batch->user_id);

        $codexCount = 0;

        foreach ($this->codexConversations() as $codexConversation) {
            $context = new ImportContext(
                userId: $batch->user_id,
                filePath: $batch->file_path,
                sourceFormat: ImportFormat::ChatGptZip,
                rawChecksum: $checksum,
                sourceFile: 'codex.json',
            );
            $canonical = $this->parseCodexConversation($codexConversation, $context);
            $conversation = WriteCanonicalConversation::make()->execute($context, $canonical);

            $this->storeConversationAttachments($conversation, $checksum);
            $codexCount++;

            unset($canonical, $conversation);
        }

        if ($codexCount > 0 && $progress !== null) {
            $progress("Imported {$codexCount} Codex conversations.");
        }

        if ($progress !== null) {
            $progress('Importing Library files and remaining assets…');
        }
        $this->importLibraryFiles($batch, $checksum);
        $this->importUnlinkedArchiveAssets($batch, $checksum);
    }

    private function storeConversationAttachments(
        Conversation $conversation,
        string $checksum,
    ): void {
        Attachment::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('source_attachment_id')
            ->eachById(function (Attachment $attachment) use ($conversation, $checksum): void {
                $stored = $this->storeAsset(
                    $attachment->source_attachment_id,
                    $conversation->user_id,
                    $checksum,
                    $attachment->filename,
                    $attachment->mime_type,
                );

                if ($stored !== null) {
                    $attachment->update([
                        ...$stored,
                        'attachment_type' => $this->attachmentType($stored['mime_type']),
                    ]);
                }
            });
    }

    private function importLibraryFiles(
        ImportBatch $batch,
        string $checksum,
    ): void {
        foreach ($this->libraryFiles() as $libraryFile) {
            $sourceId = $this->sourceId(
                $libraryFile['file_id']
                ?? $libraryFile['id']
                ?? null,
            );

            if ($sourceId === null) {
                continue;
            }

            $filename = isset($libraryFile['file_name'])
                ? Str::limit((string) $libraryFile['file_name'], 255, '')
                : null;
            $mimeType = isset($libraryFile['mime_type']) ? (string) $libraryFile['mime_type'] : null;
            $stored = $this->storeAsset(
                $sourceId,
                $batch->user_id,
                $checksum,
                $filename,
                $mimeType,
            );
            $filename = $stored['filename'] ?? $filename;
            $attachments = Attachment::query()
                ->where('user_id', $batch->user_id)
                ->where('source_platform', SourcePlatform::ChatGpt->value)
                ->where('source_attachment_id', $sourceId)
                ->get();

            if ($attachments->isNotEmpty()) {
                foreach ($attachments as $attachment) {
                    $sourceRef = is_array($attachment->source_ref) ? $attachment->source_ref : [];
                    $attachment->update([
                        'filename' => $filename ?: $attachment->filename,
                        'mime_type' => $mimeType ?: $attachment->mime_type,
                        'byte_size' => $stored['byte_size']
                            ?? (is_numeric($libraryFile['file_size_bytes'] ?? null)
                                ? (int) $libraryFile['file_size_bytes']
                                : $attachment->byte_size),
                        'checksum' => $stored['checksum'] ?? $attachment->checksum,
                        'storage_path' => $stored['storage_path'] ?? $attachment->storage_path,
                        'source_ref' => [
                            ...$sourceRef,
                            'library_file' => $libraryFile,
                        ],
                    ]);
                }

                continue;
            }

            $message = $this->findOriginMessage($batch->user_id, $libraryFile);
            $conversation = $message?->conversation;

            Attachment::query()->create([
                'user_id' => $batch->user_id,
                'conversation_id' => $conversation?->id,
                'message_id' => $message?->id,
                'source_platform' => SourcePlatform::ChatGpt->value,
                'source_attachment_id' => $sourceId,
                'attachment_type' => $this->attachmentType($mimeType),
                'filename' => $filename,
                'mime_type' => $mimeType,
                'byte_size' => $stored['byte_size']
                    ?? (is_numeric($libraryFile['file_size_bytes'] ?? null)
                        ? (int) $libraryFile['file_size_bytes']
                        : null),
                'checksum' => $stored['checksum'] ?? null,
                'storage_path' => $stored['storage_path'] ?? null,
                'source_ref' => ['library_file' => $libraryFile],
            ]);
        }
    }

    private function applyConversationSupplementals(int $userId): void
    {
        foreach ($this->sharedConversations() as $sharedConversation) {
            $sourceConversationId = $sharedConversation['conversation_id'] ?? null;

            if (! is_string($sourceConversationId) || $sourceConversationId === '') {
                continue;
            }

            $conversation = $this->findChatGptConversation($userId, $sourceConversationId);

            if ($conversation === null) {
                continue;
            }

            $metadata = $conversation->metadata ?? [];
            $shareId = $sharedConversation['id'] ?? null;
            $metadata['shared_conversations'][] = [
                ...$sharedConversation,
                'source_url' => is_string($shareId) && $shareId !== ''
                    ? 'https://chatgpt.com/share/'.$shareId
                    : null,
            ];
            $conversation->update(['metadata' => $metadata]);
        }

        foreach ($this->messageFeedback() as $feedback) {
            $sourceConversationId = $feedback['conversation_id'] ?? null;

            if (! is_string($sourceConversationId) || $sourceConversationId === '') {
                continue;
            }

            $conversation = $this->findChatGptConversation($userId, $sourceConversationId);

            if ($conversation === null) {
                continue;
            }

            $metadata = $conversation->metadata ?? [];
            $metadata['message_feedback'][] = $feedback;
            $conversation->update(['metadata' => $metadata]);
        }
    }

    private function importUnlinkedArchiveAssets(
        ImportBatch $batch,
        string $checksum,
    ): void {
        foreach ($this->assetSourceIds() as $sourceId) {
            $exists = Attachment::query()
                ->where('user_id', $batch->user_id)
                ->where('source_attachment_id', $sourceId)
                ->exists();

            if ($exists) {
                continue;
            }

            $stored = $this->storeAsset(
                $sourceId,
                $batch->user_id,
                $checksum,
            );

            if ($stored === null) {
                continue;
            }

            Attachment::query()->create([
                'user_id' => $batch->user_id,
                'source_platform' => SourcePlatform::ChatGpt->value,
                'source_attachment_id' => $sourceId,
                'attachment_type' => $this->attachmentType($stored['mime_type']),
                'filename' => $stored['filename'],
                'mime_type' => $stored['mime_type'],
                'byte_size' => $stored['byte_size'],
                'checksum' => $stored['checksum'],
                'storage_path' => $stored['storage_path'],
                'source_ref' => ['archive_unlinked' => true],
            ]);
        }
    }

    private function findChatGptConversation(int $userId, string $sourceConversationId): ?Conversation
    {
        return Conversation::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('source_platform', SourcePlatform::ChatGpt->value)
            ->where('source_conversation_id', $sourceConversationId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $libraryFile
     */
    private function findOriginMessage(int $userId, array $libraryFile): ?Message
    {
        $messageId = $libraryFile['origination_message_id'] ?? null;

        if (is_string($messageId) && $messageId !== '') {
            $message = Message::query()
                ->where('source_message_id', $messageId)
                ->whereHas('conversation', fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->where('user_id', $userId)
                    ->where('source_platform', SourcePlatform::ChatGpt->value))
                ->first();

            if ($message !== null) {
                return $message;
            }
        }

        $threadId = $libraryFile['origination_thread_id']
            ?? $libraryFile['initiating_conversation_id']
            ?? null;

        if (! is_string($threadId) || $threadId === '') {
            return null;
        }

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('source_platform', SourcePlatform::ChatGpt->value)
            ->where('source_conversation_id', $threadId)
            ->first();

        if ($conversation === null) {
            return null;
        }

        return $conversation->canonicalLeafMessage
            ?? $conversation->messages()->orderByDesc('created_at')->first();
    }

    /** @param array<string, mixed> $data */
    public function parseCodexConversation(array $data, ImportContext $context): CanonicalConversation
    {
        $turns = is_array($data['turns'] ?? null) ? $data['turns'] : [];
        $messages = [];
        $previousMessageId = null;

        foreach ($turns as $index => $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $sourceMessageId = (string) ($turn['id'] ?? 'turn-'.($index + 1));
            $items = is_array($turn['input_items'] ?? null)
                ? $turn['input_items']
                : (is_array($turn['output_items'] ?? null) ? $turn['output_items'] : []);
            [$blocks, $attachments] = $this->parseCodexItems($items);

            $messages[] = new CanonicalMessage(
                sourceMessageId: $sourceMessageId,
                parentSourceMessageId: filled($turn['previous_turn_id'] ?? null)
                    ? (string) $turn['previous_turn_id']
                    : $previousMessageId,
                role: MessageRole::normalize($turn['role'] ?? null),
                actorName: 'Codex',
                createdAt: null,
                isOnCanonicalPath: true,
                isHidden: false,
                blocks: $blocks,
                metadata: [
                    ...Arr::except($turn, ['input_items', 'output_items']),
                    'position' => $index,
                ],
                attachments: $attachments,
            );

            $previousMessageId = $sourceMessageId;
        }

        return new CanonicalConversation(
            title: (string) ($data['title'] ?? 'Untitled Codex conversation'),
            sourcePlatform: SourcePlatform::Codex,
            sourceConversationId: (string) ($data['id'] ?? 'codex-'.hash('sha256', serialize($data))),
            messages: $messages,
            metadata: Arr::except($data, ['turns', 'title', 'id']),
            sources: [CanonicalConversationSource::fromImportContext($context, 'codex.json')],
            canonicalLeafSourceMessageId: $previousMessageId,
            projectIdentifiers: ExtractProjectIdentifiers::make()->execute(SourcePlatform::Codex, $turns),
        );
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array{0: array<int, CanonicalContentBlock>, 1: array<int, CanonicalAttachment>}
     */
    private function parseCodexItems(array $items): array
    {
        $blocks = [];
        $attachments = [];
        $position = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = (string) ($item['type'] ?? 'other');

            if ($type === 'message') {
                $parts = is_array($item['content'] ?? null) ? $item['content'] : [];

                foreach ($parts as $part) {
                    if (! is_array($part)) {
                        continue;
                    }

                    $contentType = (string) ($part['content_type'] ?? 'other');
                    $text = is_string($part['text'] ?? null) ? trim($part['text']) : null;

                    $blocks[] = new CanonicalContentBlock(
                        position: $position++,
                        blockType: $contentType === 'text' ? BlockType::Text : BlockType::Other,
                        textContent: filled($text) ? $text : $this->codexSearchableText($contentType, $part),
                        structuredContent: $part,
                        metadata: ['content_type' => $contentType],
                    );
                }

                continue;
            }

            if ($type === 'image_asset_pointer') {
                $sourceId = $this->codexSourceId($item['asset_pointer'] ?? null);
                $attachment = new CanonicalAttachment(
                    sourceAttachmentId: $sourceId,
                    attachmentType: AttachmentType::Image,
                    byteSize: is_numeric($item['size_bytes'] ?? null) ? (int) $item['size_bytes'] : null,
                    sourceRef: $item,
                );

                $blocks[] = new CanonicalContentBlock(
                    position: $position++,
                    blockType: BlockType::Image,
                    structuredContent: $item,
                    metadata: ['content_type' => $type],
                    attachments: $sourceId !== null ? [$attachment] : [],
                );

                if ($sourceId !== null) {
                    $attachments[$sourceId] = $attachment;
                }

                continue;
            }

            $blocks[] = new CanonicalContentBlock(
                position: $position++,
                blockType: in_array($type, ['pr', 'follow_up_diff'], true)
                    ? BlockType::ToolResult
                    : BlockType::Other,
                textContent: $this->codexSearchableText($type, $item),
                structuredContent: $item,
                metadata: ['content_type' => $type],
            );
        }

        return [$blocks, array_values($attachments)];
    }

    /** @param array<string, mixed> $payload */
    private function codexSearchableText(string $type, array $payload): ?string
    {
        $values = [$type];

        foreach (['path', 'commit_message', 'pr_title', 'pr_message', 'branch', 'branch_name'] as $key) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                $values[] = trim($payload[$key]);
            }
        }

        return $values !== [$type] ? implode("\n", $values) : null;
    }

    private function codexSourceId(mixed $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        return preg_match('/(?:^|[\/:])(file-[A-Za-z0-9]+|file_[A-Za-z0-9]+)(?:$|[.\/?#-])/', $reference, $matches) === 1
            ? $matches[1]
            : null;
    }

    public function __destruct()
    {
        if (isset($this->zip)) {
            $this->zip->close();
        }
    }

    private function openArchive(string $path): void
    {
        if (isset($this->zip)) {
            $this->zip->close();
        }

        $this->entryNames = [];
        $this->assetEntriesBySourceId = [];
        $this->assetFilenamesBySourceId = [];
        $this->storedAssets = [];
        $this->zip = new ZipArchive;

        if ($this->zip->open($path) !== true) {
            throw new \RuntimeException('Failed to open ChatGPT export ZIP archive.');
        }

        $this->indexEntries();
        $this->indexAssets();
    }

    /**
     * @return Generator<int, array{conversation: array<string, mixed>, source_file: string}>
     */
    private function conversations(): Generator
    {
        $entries = array_values(array_filter(
            $this->entryNames,
            static fn (string $entry): bool => preg_match(
                '/(?:^|\/)conversations(?:-\d+)?\.json$/',
                $entry,
            ) === 1,
        ));

        natsort($entries);

        if ($entries === []) {
            throw new \RuntimeException(
                'ChatGPT export ZIP does not contain conversations.json or conversations-NNN.json files.'
            );
        }

        foreach ($entries as $entry) {
            foreach ($this->streamJsonObjects($entry) as $conversation) {
                if (! isset($conversation['mapping'], $conversation['current_node'])) {
                    continue;
                }

                yield [
                    'conversation' => $conversation,
                    'source_file' => $entry,
                ];
            }
        }
    }

    /** @return Generator<int, array<string, mixed>> */
    private function streamJsonObjects(string $entry): Generator
    {
        $stream = $this->zip->getStream($entry);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Failed to read {$entry} from ChatGPT export ZIP.");
        }

        $root = null;
        $capturing = false;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $buffer = '';
        $closed = false;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 64 * 1024);

                if ($chunk === false) {
                    throw new \RuntimeException("Failed while streaming {$entry} from ChatGPT export ZIP.");
                }

                $length = strlen($chunk);
                $segmentStart = $capturing ? 0 : null;

                for ($index = 0; $index < $length; $index++) {
                    $character = $chunk[$index];

                    if (! $capturing) {
                        if ($root === null) {
                            if (ctype_space($character)) {
                                continue;
                            }

                            if ($character === '[') {
                                $root = 'array';

                                continue;
                            }

                            if ($character === '{') {
                                $root = 'object';
                            } else {
                                throw new \RuntimeException("{$entry} has an unexpected JSON structure.");
                            }
                        } elseif ($root === 'array') {
                            if (ctype_space($character) || $character === ',') {
                                continue;
                            }

                            if ($character === ']') {
                                $closed = true;

                                break 2;
                            }

                            if ($character !== '{') {
                                throw new \RuntimeException("{$entry} must contain a JSON array of objects.");
                            }
                        } else {
                            throw new \RuntimeException("{$entry} contains trailing JSON data.");
                        }

                        $capturing = true;
                        $depth = 0;
                        $inString = false;
                        $escaped = false;
                        $segmentStart = $index;
                    }

                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\') {
                            $escaped = true;
                        } elseif ($character === '"') {
                            $inString = false;
                        }
                    } elseif ($character === '"') {
                        $inString = true;
                    } elseif ($character === '{' || $character === '[') {
                        $depth++;
                    } elseif ($character === '}' || $character === ']') {
                        $depth--;
                    }

                    if ($depth !== 0 || $inString) {
                        continue;
                    }

                    $buffer .= substr($chunk, $segmentStart, $index - $segmentStart + 1);
                    $decoded = json_decode($buffer, true);

                    if (! is_array($decoded)) {
                        throw new \RuntimeException("{$entry} contains an invalid JSON object.");
                    }

                    yield $decoded;

                    $buffer = '';
                    $capturing = false;
                    $segmentStart = null;

                    if ($root === 'object') {
                        $closed = true;

                        break 2;
                    }
                }

                if ($capturing && $segmentStart !== null) {
                    $buffer .= substr($chunk, $segmentStart);
                }
            }
        } finally {
            fclose($stream);
        }

        if (! $closed || $capturing) {
            throw new \RuntimeException("{$entry} contains incomplete JSON.");
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function libraryFiles(): array
    {
        return $this->arrayRecords('library_files.json');
    }

    /** @return array<int, array<string, mixed>> */
    private function codexConversations(): array
    {
        return $this->arrayRecords('codex.json');
    }

    /** @return array<int, array<string, mixed>> */
    private function sharedConversations(): array
    {
        return $this->arrayRecords('shared_conversations.json');
    }

    /** @return array<int, array<string, mixed>> */
    private function messageFeedback(): array
    {
        return $this->arrayRecords('message_feedback.json');
    }

    /** @return array<int, array<string, mixed>> */
    private function arrayRecords(string $basename): array
    {
        $entry = $this->findEntryByBasename($basename);

        if ($entry === null) {
            return [];
        }

        $decoded = $this->decodeJsonEntry($entry);

        return array_is_list($decoded)
            ? array_values(array_filter($decoded, 'is_array'))
            : [];
    }

    private function sourceId(mixed $reference): ?string
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        if (isset($this->assetEntriesBySourceId[$reference])) {
            return $reference;
        }

        if (preg_match('/(?:^|[\/:])(file-[A-Za-z0-9]+|file_[A-Za-z0-9]+)(?:$|[.\/?#-])/', $reference, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /** @return array<int, string> */
    private function assetSourceIds(): array
    {
        return array_keys($this->assetEntriesBySourceId);
    }

    /**
     * @return array{filename: string|null, mime_type: string|null, byte_size: int|null, checksum: string, storage_path: string}|null
     */
    private function storeAsset(
        mixed $reference,
        int $userId,
        string $exportChecksum,
        ?string $filename = null,
        ?string $mimeType = null,
    ): ?array {
        $sourceId = $this->sourceId($reference);

        if ($sourceId === null || ! isset($this->assetEntriesBySourceId[$sourceId])) {
            return null;
        }

        $entry = $this->assetEntriesBySourceId[$sourceId];
        $filename = $filename
            ?: ($this->assetFilenamesBySourceId[$sourceId] ?? null)
            ?: $this->filenameFromEntry($entry, $sourceId);
        $filename = filled($filename) ? Str::limit((string) $filename, 255, '') : null;
        $mimeType = $this->normalizeMimeType($mimeType, $filename);

        if (! isset($this->storedAssets[$sourceId])) {
            $checksum = $this->checksumForEntry($entry);
            $extension = $this->extension($filename, $mimeType);
            $storagePath = sprintf(
                'attachments/%d/chatgpt/%s/%s%s',
                $userId,
                $exportChecksum,
                hash('sha256', $sourceId),
                $extension !== '' ? '.'.$extension : '',
            );

            if (! Storage::exists($storagePath)) {
                $stream = $this->zip->getStream($entry);

                if (! is_resource($stream)) {
                    throw new \RuntimeException("Failed to read attachment {$sourceId} from ChatGPT export.");
                }

                try {
                    if (! Storage::writeStream($storagePath, $stream)) {
                        throw new \RuntimeException("Failed to store attachment {$sourceId}.");
                    }
                } finally {
                    fclose($stream);
                }
            }

            $stat = $this->zip->statName($entry);
            $this->storedAssets[$sourceId] = [
                'storage_path' => $storagePath,
                'checksum' => $checksum,
                'byte_size' => is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : null,
            ];
        }

        return [
            'filename' => $filename,
            'mime_type' => $mimeType,
            ...$this->storedAssets[$sourceId],
        ];
    }

    private function indexEntries(): void
    {
        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $entry = $this->zip->getNameIndex($index);

            if (! is_string($entry) || $this->shouldIgnoreEntry($entry)) {
                continue;
            }

            $this->entryNames[] = $entry;
        }
    }

    private function indexAssets(): void
    {
        $manifestEntry = $this->findEntryByBasename('export_manifest.json', excludeSuffix: '/sites/export_manifest.json');

        if ($manifestEntry !== null) {
            $manifest = $this->decodeJsonEntry($manifestEntry);
            $logicalFiles = is_array($manifest['logical_files'] ?? null) ? $manifest['logical_files'] : [];

            foreach ($logicalFiles as $logicalPath => $description) {
                if (! is_string($logicalPath) || ! is_array($description)) {
                    continue;
                }

                $physicalFiles = is_array($description['files'] ?? null) ? $description['files'] : [];
                $physicalPath = isset($physicalFiles[0]) && is_string($physicalFiles[0])
                    ? $physicalFiles[0]
                    : $logicalPath;
                $entry = $this->findEntryByRelativePath($physicalPath);
                $sourceId = $this->sourceId($logicalPath) ?? $this->sourceId($physicalPath);

                if ($sourceId === null && $this->isAssetLogicalPath($logicalPath)) {
                    $sourceId = 'archive:'.hash('sha256', $logicalPath);
                }

                if ($entry === null || $sourceId === null) {
                    continue;
                }

                $this->assetEntriesBySourceId[$sourceId] = $entry;

                $filename = $this->filenameFromLogicalPath($logicalPath, $sourceId);

                if ($filename !== null) {
                    $this->assetFilenamesBySourceId[$sourceId] = $filename;
                }
            }
        }

        foreach ($this->entryNames as $entry) {
            $sourceId = $this->sourceId(basename($entry));

            if ($sourceId !== null) {
                $this->assetEntriesBySourceId[$sourceId] ??= $entry;
            }
        }

        $assetNamesEntry = $this->findEntryByBasename('conversation_asset_file_names.json');

        if ($assetNamesEntry === null) {
            return;
        }

        $assetNames = $this->decodeJsonEntry($assetNamesEntry);

        foreach ($assetNames as $assetPath => $filename) {
            $sourceId = $this->sourceId($assetPath);

            if ($sourceId === null || ! is_string($filename) || trim($filename) === '') {
                continue;
            }

            $this->assetFilenamesBySourceId[$sourceId] = basename($filename);

            $entry = $this->findEntryByRelativePath((string) $assetPath);

            if ($entry !== null) {
                $this->assetEntriesBySourceId[$sourceId] = $entry;
            }
        }
    }

    /** @return array<string, mixed>|array<int, mixed> */
    private function decodeJsonEntry(string $entry): array
    {
        $contents = $this->zip->getFromName($entry);

        if (! is_string($contents)) {
            throw new \RuntimeException("Failed to read {$entry} from ChatGPT export ZIP.");
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException("{$entry} in ChatGPT export ZIP is not valid JSON.");
        }

        return $decoded;
    }

    private function checksumForEntry(string $entry): string
    {
        $stream = $this->zip->getStream($entry);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Failed to read attachment {$entry} from ChatGPT export.");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function findEntryByBasename(string $basename, ?string $excludeSuffix = null): ?string
    {
        foreach ($this->entryNames as $entry) {
            if (basename($entry) !== $basename) {
                continue;
            }

            if ($excludeSuffix !== null && str_ends_with('/'.$entry, $excludeSuffix)) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    private function findEntryByRelativePath(string $path): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        foreach ($this->entryNames as $entry) {
            if ($entry === $normalized || str_ends_with($entry, '/'.$normalized)) {
                return $entry;
            }
        }

        return null;
    }

    private function filenameFromLogicalPath(string $path, string $sourceId): ?string
    {
        $basename = basename($path);

        if ($basename === $sourceId.'.dat' || $basename === $sourceId) {
            return null;
        }

        if (str_starts_with($basename, $sourceId.'-')) {
            return substr($basename, strlen($sourceId) + 1) ?: null;
        }

        return $basename;
    }

    private function filenameFromEntry(string $entry, string $sourceId): ?string
    {
        return $this->filenameFromLogicalPath($entry, $sourceId);
    }

    private function normalizeMimeType(?string $mimeType, ?string $filename): ?string
    {
        $mimeType = trim((string) $mimeType);

        if ($mimeType !== '') {
            return $mimeType;
        }

        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        if ($extension === '') {
            return null;
        }

        return MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;
    }

    private function extension(?string $filename, ?string $mimeType): string
    {
        $extension = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));

        if ($extension !== '' && $extension !== 'dat') {
            return preg_replace('/[^a-z0-9]+/', '', $extension) ?? '';
        }

        return MimeTypes::getDefault()->getExtensions((string) $mimeType)[0] ?? '';
    }

    private function shouldIgnoreEntry(string $entry): bool
    {
        return str_ends_with($entry, '/')
            || str_starts_with($entry, '__MACOSX/')
            || str_starts_with(basename($entry), '._');
    }

    private function isAssetLogicalPath(string $path): bool
    {
        if (basename($path) === 'chat.html') {
            return false;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, [
            'aac', 'avi', 'bmp', 'csv', 'doc', 'docx', 'gif', 'heic', 'html', 'jpeg', 'jpg',
            'jsonl', 'm4a', 'md', 'mov', 'mp3', 'mp4', 'pdf', 'png', 'ppt', 'pptx', 'sql',
            'svg', 'tsv', 'txt', 'wav', 'webm', 'webp', 'xls', 'xlsx', 'yaml', 'yml', 'zip',
        ], true);
    }

    private function attachmentType(?string $mimeType): AttachmentType
    {
        return AttachmentType::fromMimeType($mimeType);
    }
}
