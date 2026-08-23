<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use App\Actions\Projects\ExtractProjectIdentifiers;
use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\Enums\MessageRole;
use App\Enums\SourcePlatform;
use App\ValueObjects\CanonicalAttachment;
use App\ValueObjects\CanonicalContentBlock;
use App\ValueObjects\CanonicalConversation;
use App\ValueObjects\CanonicalConversationSource;
use App\ValueObjects\CanonicalMessage;
use App\ValueObjects\ImportContext;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ParseCursorJsonlConversation extends Action
{
    public function execute(ImportContext $ctx, string $contents): CanonicalConversation
    {
        $lines = Str::of($contents)
            ->split('/\r\n|\n|\r/')
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => filled($line))
            ->values();

        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cursor JSONL export contains no messages.');
        }

        $previousMessageId = null;
        $conversationId = null;
        $title = null;
        $lastCreatedAt = null;

        $messages = $lines->map(function (string $line, int $index) use ($ctx, &$previousMessageId, &$conversationId, &$title, &$lastCreatedAt): CanonicalMessage {
            $row = json_decode($line, true);

            if (! is_array($row)) {
                throw new InvalidArgumentException('Cursor JSONL export contains invalid JSON on line '.($index + 1).'.');
            }

            $conversationId ??= $this->resolveConversationId($row, $ctx);
            $title ??= $this->resolveTitle($row, $ctx);

            $sourceMessageId = (string) (
                $row['id']
                ?? $row['message_id']
                ?? $row['uuid']
                ?? 'line-'.($index + 1)
            );

            $parentSourceMessageId = isset($row['parent_message_id'])
                ? (string) $row['parent_message_id']
                : (isset($row['parent_id']) ? (string) $row['parent_id'] : $previousMessageId);

            $sourceRole = $row['role'] ?? $row['type'] ?? null;
            $role = MessageRole::normalize($sourceRole);
            $actorName = isset($row['name']) ? (string) $row['name'] : null;

            $contentItems = $row['message']['content'] ?? $row['content'] ?? [];

            if (! is_array($contentItems)) {
                $contentItems = [];
            }

            $explicitCreatedAt = $this->resolveExplicitCreatedAt($row, $contentItems);
            if ($explicitCreatedAt !== null) {
                $lastCreatedAt = $explicitCreatedAt;
            }

            $createdAt = $explicitCreatedAt ?? $lastCreatedAt;

            $blocks = $this->parseContentItems($contentItems, $role);

            if ($title === null && $role === MessageRole::User) {
                foreach ($blocks as $block) {
                    if ($block->blockType === BlockType::Text && filled($block->textContent)) {
                        $title = BuildCursorConversationTitle::make()->execute($block->textContent);
                        break;
                    }
                }
            }

            $isHidden = $this->messageIsHidden($blocks);

            $message = new CanonicalMessage(
                sourceMessageId: $sourceMessageId,
                parentSourceMessageId: $parentSourceMessageId,
                role: $role,
                actorName: $actorName,
                createdAt: $createdAt,
                isOnCanonicalPath: true,
                isHidden: $isHidden,
                blocks: $blocks,
                metadata: [
                    ...(is_array($row['metadata'] ?? null) ? $row['metadata'] : []),
                    ...($role === MessageRole::Unknown && is_string($sourceRole)
                        ? ['_source_role' => $sourceRole]
                        : []),
                ],
            );

            $previousMessageId = $sourceMessageId;

            return $message;
        })->all();

        $leafMessageId = $messages !== [] ? $messages[array_key_last($messages)]->sourceMessageId : null;

        return new CanonicalConversation(
            title: $title ?? $this->titleFromFilePath($ctx->filePath),
            sourcePlatform: SourcePlatform::Cursor,
            sourceConversationId: $conversationId ?? Str::uuid()->toString(),
            messages: $messages,
            metadata: $ctx->metadata,
            sources: [CanonicalConversationSource::fromImportContext($ctx)],
            canonicalLeafSourceMessageId: $leafMessageId,
            projectIdentifiers: ExtractProjectIdentifiers::make()->execute(
                SourcePlatform::Cursor,
                $ctx->metadata['cursor_workspace'] ?? null,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveConversationId(array $row, ImportContext $ctx): string
    {
        foreach (['session_id', 'conversation_id', 'chat_id', 'composer_id'] as $key) {
            if (! empty($row[$key])) {
                return (string) $row[$key];
            }
        }

        $filename = pathinfo($ctx->sourceFile ?? $ctx->filePath, PATHINFO_FILENAME);

        if (Str::isUuid($filename)) {
            $workspace = $ctx->metadata['cursor_workspace'] ?? null;
            $parentTranscriptId = $ctx->metadata['cursor_parent_transcript_id'] ?? null;

            if (is_string($workspace) && $workspace !== '') {
                return implode(':', array_filter([
                    $workspace,
                    is_string($parentTranscriptId) ? $parentTranscriptId : null,
                    $filename,
                ]));
            }

            return $filename;
        }

        return Str::slug($filename).'-'.substr($ctx->rawChecksum, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveTitle(array $row, ImportContext $ctx): ?string
    {
        foreach (['title', 'conversation_title', 'name'] as $key) {
            if (! empty($row[$key]) && is_string($row[$key])) {
                return $row[$key];
            }
        }

        return null;
    }

    private function titleFromFilePath(string $filePath): string
    {
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        return $filename !== '' ? Str::headline($filename) : 'Untitled conversation';
    }

    /**
     * @param  array<int, mixed>  $contentItems
     * @return array<int, CanonicalContentBlock>
     */
    private function parseContentItems(array $contentItems, MessageRole $role): array
    {
        $blocks = [];
        $position = 0;

        foreach ($contentItems as $item) {
            if (! is_array($item)) {
                if (is_string($item) && trim($item) !== '') {
                    $text = $this->sanitizeTextForRole($item, $role);

                    if (trim($text) !== '') {
                        $blocks[] = new CanonicalContentBlock(
                            position: $position++,
                            blockType: BlockType::Text,
                            textContent: $text,
                        );
                    }
                }

                continue;
            }

            $type = (string) ($item['type'] ?? 'text');

            $block = match ($type) {
                'text' => $this->textBlock($item, $position, $role),
                'tool_use' => $this->toolUseBlock($item, $position),
                'tool_result' => $this->toolResultBlock($item, $position),
                'thinking' => $this->thinkingBlock($item, $position),
                'image' => $this->imageBlock($item, $position),
                default => $this->otherBlock($item, $position, $type),
            };

            if ($block !== null) {
                $blocks[] = $block;
                $position++;
            }
        }

        return $blocks;
    }

    private function sanitizeTextForRole(string $text, MessageRole $role): string
    {
        return match ($role) {
            MessageRole::User => SanitizeCursorUserMessage::make()->execute($text),
            MessageRole::Assistant => SanitizeCursorAssistantMessage::make()->execute($text),
            default => trim($text),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function textBlock(array $item, int $position, MessageRole $role): ?CanonicalContentBlock
    {
        $text = $this->sanitizeTextForRole((string) ($item['text'] ?? $item['content'] ?? ''), $role);

        if (trim($text) === '') {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Text,
            textContent: $text,
            structuredContent: $item,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toolUseBlock(array $item, int $position): ?CanonicalContentBlock
    {
        $name = (string) ($item['name'] ?? 'tool');
        $input = $item['input'] ?? null;

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::ToolUse,
            textContent: BuildCursorToolSearchText::make()->execute($name, $input),
            structuredContent: $item,
            metadata: [
                'tool_name' => $name,
                'collapsed_by_default' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toolResultBlock(array $item, int $position): ?CanonicalContentBlock
    {
        $name = (string) ($item['name'] ?? 'tool_result');
        $output = $item['output'] ?? $item['content'] ?? null;

        if ($output === null && ! isset($item['text'])) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::ToolResult,
            textContent: BuildCursorToolSearchText::make()->execute($name, is_array($output) ? $output : ['output' => $output]),
            structuredContent: $item,
            metadata: [
                'tool_name' => $name,
                'collapsed_by_default' => true,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function thinkingBlock(array $item, int $position): ?CanonicalContentBlock
    {
        $text = (string) ($item['thinking'] ?? $item['text'] ?? $item['content'] ?? '');

        if ($this->isBlank($text, $item)) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Reasoning,
            textContent: $text,
            structuredContent: $item,
            metadata: ['hidden_by_default' => true],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function imageBlock(array $item, int $position): ?CanonicalContentBlock
    {
        $url = (string) ($item['url'] ?? $item['image_url'] ?? $item['source']['url'] ?? '');

        if ($this->isBlank($url, $item)) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Image,
            textContent: $url,
            structuredContent: $item,
            attachments: [new CanonicalAttachment(
                attachmentType: AttachmentType::Image,
                externalUrl: $url,
                sourceRef: $item,
            )],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function otherBlock(array $item, int $position, string $type): ?CanonicalContentBlock
    {
        $text = (string) ($item['text'] ?? $item['content'] ?? '');

        if ($this->isBlank($text, $item)) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Other,
            textContent: $text !== '' ? $text : null,
            structuredContent: $item,
            metadata: ['source_type' => $type],
        );
    }

    /**
     * @param  array<int, CanonicalContentBlock>  $blocks
     */
    private function messageIsHidden(array $blocks): bool
    {
        if ($blocks === []) {
            return false;
        }

        return collect($blocks)->every(
            fn (CanonicalContentBlock $block): bool => $block->blockType === BlockType::Reasoning,
        );
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function isBlank(?string $text, ?array $structured): bool
    {
        if (trim((string) $text) !== '') {
            return false;
        }

        return $structured === null || $structured === [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, mixed>  $contentItems
     */
    private function resolveExplicitCreatedAt(array $row, array $contentItems): ?Carbon
    {
        $createdAt = $this->parseTimestamp(
            $row['timestamp']
            ?? $row['created_at']
            ?? $row['createdAt']
            ?? null,
        );

        if ($createdAt !== null) {
            return $createdAt;
        }

        return $this->extractTimestampFromContent($contentItems);
    }

    /**
     * @param  array<int, mixed>  $contentItems
     */
    private function extractTimestampFromContent(array $contentItems): ?Carbon
    {
        foreach ($contentItems as $item) {
            if (is_string($item)) {
                $timestamp = ExtractCursorTimestamp::make()->execute($item);

                if ($timestamp !== null) {
                    return $this->parseTimestamp($timestamp);
                }

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $text = (string) ($item['text'] ?? $item['content'] ?? '');

            if ($text === '') {
                continue;
            }

            $timestamp = ExtractCursorTimestamp::make()->execute($text);

            if ($timestamp !== null) {
                return $this->parseTimestamp($timestamp);
            }
        }

        return null;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $numeric = (float) $value;

            if ($numeric > 1_000_000_000_000) {
                return Carbon::createFromTimestampMs((int) $numeric);
            }

            return Carbon::createFromTimestamp((int) $numeric);
        }

        if (is_string($value)) {
            return ParseCursorTimestamp::make()->execute($value);
        }

        return null;
    }
}
