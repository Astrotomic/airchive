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
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ParseChatGptConversation extends Action
{
    /**
     * @param  string|array<array-key, mixed>  $contents
     * @return array<int, CanonicalConversation>
     */
    public function execute(ImportContext $ctx, string|array $contents): array
    {
        $data = is_string($contents) ? json_decode($contents, true) : $contents;

        if (! is_array($data)) {
            throw new \InvalidArgumentException('ChatGPT export is not valid JSON.');
        }

        if (isset($data['mapping'], $data['current_node'])) {
            return [$this->parseConversation($data, $ctx)];
        }

        if (array_is_list($data)) {
            return array_values(array_map(
                fn (array $conversation): CanonicalConversation => $this->parseConversation($conversation, $ctx),
                array_filter(
                    $data,
                    static fn (mixed $conversation): bool => is_array($conversation)
                        && isset($conversation['mapping'], $conversation['current_node']),
                ),
            ));
        }

        throw new \InvalidArgumentException('ChatGPT export JSON must contain mapping and current_node.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function parseConversation(array $data, ImportContext $ctx): CanonicalConversation
    {
        /** @var array<string, array<string, mixed>> $mapping */
        $mapping = $data['mapping'] ?? [];
        $currentNode = (string) ($data['current_node'] ?? '');

        if ($currentNode === '' || $mapping === []) {
            throw new \InvalidArgumentException('ChatGPT export is missing mapping or current_node.');
        }

        $canonicalPathIds = $this->walkCanonicalPath($mapping, $currentNode);
        $messages = [];

        foreach ($mapping as $nodeId => $node) {
            if (! is_array($node)) {
                continue;
            }

            $message = $node['message'] ?? null;

            if (! is_array($message)) {
                continue;
            }

            $parentId = $node['parent'] ?? null;
            $parentSourceMessageId = is_string($parentId) && $parentId !== '' ? $parentId : null;
            $author = is_array($message['author'] ?? null) ? $message['author'] : [];
            $role = MessageRole::normalize($author['role'] ?? null);
            $actorName = isset($author['name']) ? (string) $author['name'] : null;
            $createdAt = $this->parseTimestamp($message['create_time'] ?? null);
            $content = is_array($message['content'] ?? null) ? $message['content'] : [];
            $blocks = $this->parseContentBlocks($content, $role, $message);
            $attachments = $this->parseMessageAttachments($message);

            $messages[] = new CanonicalMessage(
                sourceMessageId: (string) $nodeId,
                parentSourceMessageId: $parentSourceMessageId,
                role: $role,
                actorName: $actorName,
                createdAt: $createdAt,
                isOnCanonicalPath: isset($canonicalPathIds[$nodeId]),
                isHidden: false,
                blocks: $blocks,
                metadata: $this->messageMetadata($message),
                attachments: $attachments,
            );
        }

        $sourceConversationId = (string) (
            $data['conversation_id']
            ?? $data['id']
            ?? Str::uuid()->toString()
        );

        return new CanonicalConversation(
            title: (string) ($data['title'] ?? 'Untitled conversation'),
            sourcePlatform: SourcePlatform::ChatGpt,
            sourceConversationId: $sourceConversationId,
            messages: $messages,
            metadata: $this->conversationMetadata($data),
            sources: [CanonicalConversationSource::fromImportContext($ctx)],
            canonicalLeafSourceMessageId: $currentNode,
            projectIdentifiers: ExtractProjectIdentifiers::make()->execute(SourcePlatform::ChatGpt, $data),
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $mapping
     * @return array<string, true>
     */
    private function walkCanonicalPath(array $mapping, string $currentNode): array
    {
        $path = [];
        $nodeId = $currentNode;

        while ($nodeId !== '') {
            $path[$nodeId] = true;

            $parent = $mapping[$nodeId]['parent'] ?? null;
            $nodeId = is_string($parent) ? $parent : '';
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $message
     * @return array<int, CanonicalContentBlock>
     */
    private function parseContentBlocks(array $content, MessageRole $role, array $message): array
    {
        $contentType = (string) ($content['content_type'] ?? 'text');
        $parts = $content['parts'] ?? [];
        $blocks = [];
        $position = 0;

        if ($role === MessageRole::Assistant && $this->hasToolRecipient($message)) {
            $toolName = (string) $message['recipient'];
            $input = $this->contentPayload($content);

            $blocks[] = new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::ToolUse,
                textContent: BuildCursorToolSearchText::make()->execute($toolName, $input),
                structuredContent: [
                    'name' => $toolName,
                    'input' => $input,
                ],
                metadata: [
                    'content_type' => $contentType,
                    'tool_name' => $toolName,
                    'collapsed_by_default' => true,
                ],
            );

            return $blocks;
        }

        if ($role === MessageRole::Tool) {
            $author = is_array($message['author'] ?? null) ? $message['author'] : [];
            $toolName = (string) ($author['name'] ?? 'tool_result');
            $output = $this->contentPayload($content);

            if ($this->isBlankPayload($output)) {
                $output = is_array($message['metadata'] ?? null) ? $message['metadata'] : null;
            }

            if (! $this->isBlankPayload($output)) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position,
                    blockType: BlockType::ToolResult,
                    textContent: BuildCursorToolSearchText::make()->execute($toolName, $output),
                    structuredContent: [
                        'name' => $toolName,
                        'output' => $output,
                    ],
                    metadata: [
                        'content_type' => $contentType,
                        'tool_name' => $toolName,
                        'collapsed_by_default' => true,
                    ],
                );
            }

            return $blocks;
        }

        if ($contentType === 'code') {
            $language = (string) ($content['language'] ?? $message['metadata']['language'] ?? '');
            $text = $this->contentText($content);

            if (! $this->isBlank($text, $content)) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position++,
                    blockType: BlockType::Code,
                    textContent: $text,
                    structuredContent: $content,
                    language: $language !== '' ? $language : null,
                    metadata: ['content_type' => $contentType],
                );
            }

            return $blocks;
        }

        if ($contentType === 'thoughts') {
            $thoughts = is_array($content['thoughts'] ?? null) ? $content['thoughts'] : [];
            $text = collect($thoughts)
                ->filter(fn (mixed $thought): bool => is_array($thought))
                ->map(fn (array $thought): string => trim((string) ($thought['content'] ?? $thought['summary'] ?? '')))
                ->filter()
                ->implode("\n\n");

            if (! $this->isBlank($text, $content)) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position,
                    blockType: BlockType::Reasoning,
                    textContent: $text !== '' ? $text : null,
                    structuredContent: $content,
                    metadata: [
                        'content_type' => $contentType,
                        'collapsed_by_default' => true,
                    ],
                );
            }

            return $blocks;
        }

        if ($contentType === 'reasoning_recap') {
            $text = trim((string) ($content['content'] ?? ''));

            if (! $this->isBlank($text, $content)) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position,
                    blockType: BlockType::Reasoning,
                    textContent: $text !== '' ? $text : null,
                    structuredContent: $content,
                    metadata: [
                        'content_type' => $contentType,
                        'collapsed_by_default' => true,
                    ],
                );
            }

            return $blocks;
        }

        if ($contentType === 'computer_output' && is_array($content['screenshot'] ?? null)) {
            $screenshot = $content['screenshot'];
            $attachment = $this->attachmentFromAssetPart($screenshot, AttachmentType::Image);

            $blocks[] = new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Image,
                structuredContent: $content,
                metadata: ['content_type' => $contentType],
                attachments: $attachment !== null ? [$attachment] : [],
            );

            return $blocks;
        }

        if ($contentType === 'multimodal_text' || $contentType === 'text') {
            foreach ($parts as $part) {
                if (is_string($part)) {
                    if ($this->isBlank($part, null)) {
                        continue;
                    }

                    $blocks[] = new CanonicalContentBlock(
                        position: $position++,
                        blockType: BlockType::Text,
                        textContent: $this->resolveCitations($part, $message),
                        structuredContent: ['source_part' => $part],
                        metadata: ['content_type' => $contentType],
                    );

                    continue;
                }

                if (! is_array($part)) {
                    continue;
                }

                $block = $this->parseMultimodalPart($part, $position, $contentType);

                if ($block !== null) {
                    $blocks[] = $block;
                    $position++;
                }
            }

            return $blocks;
        }

        $text = $this->contentText($content);

        if (! $this->isBlank($text, $content)) {
            $blocks[] = new CanonicalContentBlock(
                position: $position,
                blockType: $this->mapBlockTypeForContentType($contentType, $role),
                textContent: $text !== '' ? $text : null,
                structuredContent: $content,
                metadata: ['content_type' => $contentType],
            );
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function parseMultimodalPart(array $part, int $position, string $contentType): ?CanonicalContentBlock
    {
        $assetType = (string) ($part['asset_type'] ?? $part['type'] ?? $part['content_type'] ?? '');

        if (
            in_array($assetType, ['image', 'image_asset_pointer'], true)
            || isset($part['asset_pointer'])
            || isset($part['image_url'])
        ) {
            $url = is_array($part['image_url'] ?? null)
                ? (string) ($part['image_url']['url'] ?? '')
                : (string) ($part['image_url'] ?? '');
            $externalUrl = preg_match('/^https?:\/\//i', $url) === 1 ? $url : null;
            $attachment = $this->attachmentFromAssetPart($part, AttachmentType::Image, $externalUrl);

            return new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Image,
                textContent: $externalUrl,
                structuredContent: $part,
                metadata: ['content_type' => $contentType],
                attachments: $attachment !== null ? [$attachment] : [],
            );
        }

        $text = $part['text'] ?? $part['content'] ?? null;

        if (is_string($text) && ! $this->isBlank($text, $part)) {
            return new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Text,
                textContent: $text,
                structuredContent: $part,
                metadata: ['content_type' => $contentType],
            );
        }

        if (! $this->isBlank(null, $part)) {
            return new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Other,
                structuredContent: $part,
                metadata: ['content_type' => $contentType],
            );
        }

        return null;
    }

    private function mapBlockTypeForContentType(string $contentType, MessageRole $role): BlockType
    {
        if ($role === MessageRole::Tool) {
            return BlockType::ToolResult;
        }

        return match ($contentType) {
            'code' => BlockType::Code,
            'execution_output' => BlockType::ToolResult,
            'thoughts', 'reasoning_recap' => BlockType::Reasoning,
            default => BlockType::Other,
        };
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function flattenParts(array $parts): string
    {
        $segments = [];

        foreach ($parts as $part) {
            if (is_string($part)) {
                $segments[] = $part;

                continue;
            }

            if (is_array($part)) {
                $text = $part['text'] ?? $part['content'] ?? null;

                if (is_string($text)) {
                    $segments[] = $text;
                }
            }
        }

        return trim(implode("\n", $segments));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function hasToolRecipient(array $message): bool
    {
        $recipient = trim((string) ($message['recipient'] ?? ''));

        return $recipient !== '' && $recipient !== 'all';
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function contentText(array $content): string
    {
        if (is_string($content['text'] ?? null)) {
            return trim($content['text']);
        }

        if (is_string($content['content'] ?? null)) {
            return trim($content['content']);
        }

        $parts = is_array($content['parts'] ?? null) ? $content['parts'] : [];

        return $this->flattenParts($parts);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function contentPayload(array $content): mixed
    {
        $text = $this->contentText($content);

        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $text;
    }

    private function isBlankPayload(mixed $payload): bool
    {
        if (is_string($payload)) {
            return trim($payload) === '';
        }

        return $payload === null || $payload === [];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function resolveCitations(string $text, array $message): string
    {
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $references = is_array($metadata['content_references'] ?? null)
            ? $metadata['content_references']
            : [];

        foreach ($references as $reference) {
            if (! is_array($reference) || ($reference['type'] ?? null) === 'sources_footnote') {
                continue;
            }

            $matchedText = (string) ($reference['matched_text'] ?? '');
            $alt = trim((string) ($reference['alt'] ?? ''));

            if ($matchedText === '' || $alt === '') {
                continue;
            }

            $text = str_replace($matchedText, $alt, $text);
        }

        return preg_replace('/\x{E200}cite\x{E202}[^\x{E201}]*\x{E201}/u', '', $text) ?? $text;
    }

    /**
     * @param  array<string, mixed>|null  $structured
     */
    private function isBlank(?string $text, ?array $structured): bool
    {
        $normalizedText = trim((string) $text);

        if ($normalizedText !== '') {
            return false;
        }

        return $structured === null || $structured === [];
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function conversationMetadata(array $data): array
    {
        return Arr::except($data, ['mapping', 'title']);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function messageMetadata(array $message): array
    {
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $source = Arr::except($message, ['content', 'metadata', 'id', 'create_time']);

        if ($source !== []) {
            $metadata['_source'] = $source;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, CanonicalAttachment>
     */
    private function parseMessageAttachments(array $message): array
    {
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $sourceAttachments = is_array($metadata['attachments'] ?? null) ? $metadata['attachments'] : [];
        $attachments = [];

        foreach ($sourceAttachments as $sourceAttachment) {
            if (! is_array($sourceAttachment)) {
                continue;
            }

            $sourceId = $this->sourceAttachmentId(
                $sourceAttachment['id']
                ?? $sourceAttachment['file_id']
                ?? $sourceAttachment['library_file_id']
                ?? null,
            );

            if ($sourceId === null) {
                continue;
            }

            $mimeType = $sourceAttachment['mime_type'] ?? $sourceAttachment['mimeType'] ?? null;
            $attachments[$sourceId] = new CanonicalAttachment(
                sourceAttachmentId: $sourceId,
                attachmentType: $this->attachmentType(is_string($mimeType) ? $mimeType : null),
                filename: isset($sourceAttachment['name'])
                    ? Str::limit((string) $sourceAttachment['name'], 255, '')
                    : null,
                mimeType: is_string($mimeType) ? $mimeType : null,
                byteSize: is_numeric($sourceAttachment['size'] ?? null)
                    ? (int) $sourceAttachment['size']
                    : null,
                sourceRef: $sourceAttachment,
            );
        }

        foreach ($this->assetReferences($message) as $reference) {
            $sourceId = $this->sourceAttachmentId($reference['value']);

            if ($sourceId === null || isset($attachments[$sourceId])) {
                continue;
            }

            $attachments[$sourceId] = new CanonicalAttachment(
                sourceAttachmentId: $sourceId,
                attachmentType: str_contains($reference['key'], 'image')
                    || str_contains($reference['key'], 'screenshot')
                    || $reference['key'] === 'asset_pointer'
                        ? AttachmentType::Image
                        : AttachmentType::File,
                sourceRef: [
                    'field' => $reference['key'],
                    'value' => $reference['value'],
                ],
            );
        }

        return array_values($attachments);
    }

    /**
     * @param  array<string, mixed>  $part
     */
    private function attachmentFromAssetPart(
        array $part,
        AttachmentType $attachmentType,
        ?string $externalUrl = null,
    ): ?CanonicalAttachment {
        $pointer = $part['asset_pointer'] ?? $part['image_url'] ?? $externalUrl;

        if (is_array($pointer)) {
            $pointer = $pointer['url'] ?? null;
        }

        $sourceId = $this->sourceAttachmentId($pointer);

        if ($sourceId === null && $externalUrl === null) {
            return null;
        }

        return new CanonicalAttachment(
            sourceAttachmentId: $sourceId,
            attachmentType: $attachmentType,
            byteSize: is_numeric($part['size_bytes'] ?? null) ? (int) $part['size_bytes'] : null,
            externalUrl: $externalUrl,
            sourceRef: $part,
        );
    }

    private function sourceAttachmentId(mixed $reference): ?string
    {
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        if (preg_match('/(?:^|[\/:])(file-[A-Za-z0-9]+|file_[A-Za-z0-9]+)(?:$|[.\/?#-])/', $reference, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function attachmentType(?string $mimeType): AttachmentType
    {
        return AttachmentType::fromMimeType($mimeType);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<int, array{key: string, value: string}>
     */
    private function assetReferences(array $message): array
    {
        $references = [];
        $walk = function (mixed $value, string $key = '') use (&$walk, &$references): void {
            if (is_string($value) && preg_match('/^(?:sediment|file-service):\/\//', $value) === 1) {
                $references[] = ['key' => $key, 'value' => $value];

                return;
            }

            if (! is_array($value)) {
                return;
            }

            foreach ($value as $childKey => $childValue) {
                $walk($childValue, is_string($childKey) ? $childKey : $key);
            }
        };

        $walk($message);

        return $references;
    }
}
