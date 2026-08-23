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
use App\ValueObjects\Fluent;
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
            $row = Fluent::tryFrom($line);

            if ($row === null) {
                throw new InvalidArgumentException('Cursor JSONL export contains invalid JSON on line '.($index + 1).'.');
            }

            $conversationId ??= $this->resolveConversationId($row, $ctx);
            $title ??= $this->resolveTitle($row);

            $sourceMessageId = (
                $row->scalarString('id')
                ?? $row->scalarString('message_id')
                ?? $row->scalarString('uuid')
                ?? 'line-'.($index + 1)
            );

            $parentSourceMessageId = $row->scalarString('parent_message_id')
                ?? $row->scalarString('parent_id')
                ?? $previousMessageId;

            $sourceRole = $row->get('role') ?? $row->get('type');
            $role = MessageRole::normalize($sourceRole);
            $actorName = $row->nullString('name');

            $contentItems = $row->nullArray('message.content')
                ?? $row->nullArray('content')
                ?? [];

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
                    ...($row->nullArray('metadata') ?? []),
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
     * Resolve a stable conversation identifier from one untrusted transcript row.
     */
    private function resolveConversationId(Fluent $row, ImportContext $ctx): string
    {
        foreach (['session_id', 'conversation_id', 'chat_id', 'composer_id'] as $key) {
            $identifier = $row->scalarString($key);

            if (filled($identifier)) {
                return $identifier;
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
     * Resolve a human-provided title without coercing non-string source data.
     */
    private function resolveTitle(Fluent $row): ?string
    {
        foreach (['title', 'conversation_title', 'name'] as $key) {
            $title = $row->nullString($key);

            if ($title !== null) {
                return $title;
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

            $item = new Fluent($item);
            $type = $item->nullString('type') ?? 'text';

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
     * Parse one untrusted text content item.
     */
    private function textBlock(Fluent $item, int $position, MessageRole $role): ?CanonicalContentBlock
    {
        $text = $this->sanitizeTextForRole(
            $item->nullString('text') ?? $item->nullString('content') ?? '',
            $role,
        );

        if (trim($text) === '') {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Text,
            textContent: $text,
            structuredContent: $item->all(),
        );
    }

    /**
     * Parse one untrusted tool-use content item.
     */
    private function toolUseBlock(Fluent $item, int $position): ?CanonicalContentBlock
    {
        $name = $item->nullString('name') ?? 'tool';
        $input = $item->get('input');

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::ToolUse,
            textContent: BuildCursorToolSearchText::make()->execute($name, $input),
            structuredContent: $item->all(),
            metadata: [
                'tool_name' => $name,
                'collapsed_by_default' => true,
            ],
        );
    }

    /**
     * Parse one untrusted tool-result content item.
     */
    private function toolResultBlock(Fluent $item, int $position): ?CanonicalContentBlock
    {
        $name = $item->nullString('name') ?? 'tool_result';
        $output = $item->get('output') ?? $item->get('content');

        if ($output === null && ! $item->has('text')) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::ToolResult,
            textContent: BuildCursorToolSearchText::make()->execute($name, is_array($output) ? $output : ['output' => $output]),
            structuredContent: $item->all(),
            metadata: [
                'tool_name' => $name,
                'collapsed_by_default' => true,
            ],
        );
    }

    /**
     * Parse one untrusted reasoning content item.
     */
    private function thinkingBlock(Fluent $item, int $position): ?CanonicalContentBlock
    {
        $text = $item->nullString('thinking')
            ?? $item->nullString('text')
            ?? $item->nullString('content')
            ?? '';

        if ($this->isBlank($text, $item->all())) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Reasoning,
            textContent: $text,
            structuredContent: $item->all(),
            metadata: ['hidden_by_default' => true],
        );
    }

    /**
     * Parse one untrusted image content item.
     */
    private function imageBlock(Fluent $item, int $position): ?CanonicalContentBlock
    {
        $url = $item->nullString('url')
            ?? $item->nullString('image_url')
            ?? $item->nullString('source.url')
            ?? '';

        if ($this->isBlank($url, $item->all())) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Image,
            textContent: $url,
            structuredContent: $item->all(),
            attachments: [new CanonicalAttachment(
                attachmentType: AttachmentType::Image,
                externalUrl: $url,
                sourceRef: $item->all(),
            )],
        );
    }

    /**
     * Preserve one otherwise unknown untrusted content item.
     */
    private function otherBlock(Fluent $item, int $position, string $type): ?CanonicalContentBlock
    {
        $text = $item->nullString('text') ?? $item->nullString('content') ?? '';

        if ($this->isBlank($text, $item->all())) {
            return null;
        }

        return new CanonicalContentBlock(
            position: $position,
            blockType: BlockType::Other,
            textContent: $text !== '' ? $text : null,
            structuredContent: $item->all(),
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
     * @param  array<int, mixed>  $contentItems
     */
    private function resolveExplicitCreatedAt(Fluent $row, array $contentItems): ?Carbon
    {
        $createdAt = $this->parseTimestamp(
            $row->get('timestamp')
            ?? $row->get('created_at')
            ?? $row->get('createdAt'),
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

            $item = new Fluent($item);
            $text = $item->nullString('text') ?? $item->nullString('content') ?? '';

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
