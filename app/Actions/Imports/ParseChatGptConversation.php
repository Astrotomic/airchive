<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use App\Actions\Projects\ExtractProjectIdentifiers;
use App\Collections\FluentCollection;
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

class ParseChatGptConversation extends Action
{
    /**
     * @param  string|array<array-key, mixed>  $contents
     * @return array<int, CanonicalConversation>
     */
    public function execute(ImportContext $ctx, string|array $contents): array
    {
        $data = Fluent::tryFrom($contents);

        if ($data === null) {
            throw new InvalidArgumentException('ChatGPT export is not valid JSON.');
        }

        $contents = $data->all();

        if (array_is_list($contents)) {
            return FluentCollection::from($contents)
                ->filter(fn (Fluent $conversation): bool => $this->isConversation($conversation))
                ->map(fn (Fluent $conversation) => $this->parseConversation($conversation, $ctx))
                ->values()
                ->all();
        }

        if (! $this->isConversation($data)) {
            throw new InvalidArgumentException('ChatGPT export JSON must contain mapping and current_node.');
        }

        return [$this->parseConversation($data, $ctx)];
    }

    private function isConversation(Fluent $data): bool
    {
        return $data->nullArray('mapping') !== null && $data->has('current_node');
    }

    private function parseConversation(Fluent $conversation, ImportContext $ctx): CanonicalConversation
    {
        $mapping = $conversation->collectFluent('mapping');
        $currentNode = $conversation->nullString('current_node');

        if ($mapping->isEmpty() || $currentNode === null) {
            throw new InvalidArgumentException('ChatGPT export is missing mapping or current_node.');
        }

        $canonicalPathIds = $this->walkCanonicalPath($mapping, $currentNode);

        $messages = $mapping
            ->filter(fn (Fluent $node): bool => $node->nullFluent('message') !== null)
            ->map(function (Fluent $node, int|string $nodeId) use ($canonicalPathIds): CanonicalMessage {
                $message = $node->fluent('message');
                $sourceMessageId = (string) $nodeId;
                $role = MessageRole::normalize($message->get('author.role'));

                return new CanonicalMessage(
                    sourceMessageId: $sourceMessageId,
                    parentSourceMessageId: $node->nullString('parent'),
                    role: $role,
                    actorName: $message->nullString('author.name'),
                    createdAt: $this->parseTimestamp($message->get('create_time')),
                    isOnCanonicalPath: $canonicalPathIds[$sourceMessageId] ?? false,
                    isHidden: false,
                    blocks: $this->parseContentBlocks($message->fluent('content'), $role, $message),
                    metadata: $this->messageMetadata($message),
                    attachments: $this->parseMessageAttachments($message),
                );
            })
            ->values()
            ->all();

        $sourceConversationId = $conversation->nullString('conversation_id')
            ?? $conversation->nullString('id')
            ?? Str::uuid()->toString();

        return new CanonicalConversation(
            title: $conversation->nullString('title') ?? 'Untitled conversation',
            sourcePlatform: SourcePlatform::ChatGpt,
            sourceConversationId: $sourceConversationId,
            messages: $messages,
            metadata: $this->conversationMetadata($conversation),
            sources: [CanonicalConversationSource::fromImportContext($ctx)],
            canonicalLeafSourceMessageId: $currentNode,
            projectIdentifiers: ExtractProjectIdentifiers::make()->execute(SourcePlatform::ChatGpt, $conversation->all()),
        );
    }

    /**
     * @return array<string, true>
     */
    private function walkCanonicalPath(FluentCollection $mapping, string $currentNode): array
    {
        $path = [];
        $nodeId = $currentNode;

        while ($nodeId !== null && ! isset($path[$nodeId])) {
            $path[$nodeId] = true;

            $nodeId = $mapping->get($nodeId)?->nullString('parent');
        }

        return $path;
    }

    /** @return array<int, CanonicalContentBlock> */
    private function parseContentBlocks(Fluent $content, MessageRole $role, Fluent $message): array
    {
        $contentType = $content->nullString('content_type') ?? 'text';
        $parts = $content->array('parts');
        $blocks = [];
        $position = 0;

        if ($role === MessageRole::Assistant && $this->hasToolRecipient($message)) {
            $toolName = $message->nullString('recipient') ?? 'tool';
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
            $toolName = $message->nullString('author.name') ?? 'tool_result';
            $output = $this->contentPayload($content);

            if ($this->isBlankPayload($output)) {
                $output = $message->nullArray('metadata');
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
            $language = $content->nullString('language')
                ?? $message->nullString('metadata.language')
                ?? '';
            $text = $this->contentText($content);

            if (! $this->isBlank($text, $content->all())) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position++,
                    blockType: BlockType::Code,
                    textContent: $text,
                    structuredContent: $content->all(),
                    language: $language !== '' ? $language : null,
                    metadata: ['content_type' => $contentType],
                );
            }

            return $blocks;
        }

        if ($contentType === 'thoughts') {
            $segments = [];

            foreach ($content->collectFluent('thoughts') as $thought) {
                $segment = trim($thought->nullString('content') ?? $thought->nullString('summary') ?? '');

                if ($segment !== '') {
                    $segments[] = $segment;
                }
            }

            $text = implode("\n\n", $segments);

            if (! $this->isBlank($text, $content->all())) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position,
                    blockType: BlockType::Reasoning,
                    textContent: $text !== '' ? $text : null,
                    structuredContent: $content->all(),
                    metadata: [
                        'content_type' => $contentType,
                        'collapsed_by_default' => true,
                    ],
                );
            }

            return $blocks;
        }

        if ($contentType === 'reasoning_recap') {
            $text = trim($content->nullString('content') ?? '');

            if (! $this->isBlank($text, $content->all())) {
                $blocks[] = new CanonicalContentBlock(
                    position: $position,
                    blockType: BlockType::Reasoning,
                    textContent: $text !== '' ? $text : null,
                    structuredContent: $content->all(),
                    metadata: [
                        'content_type' => $contentType,
                        'collapsed_by_default' => true,
                    ],
                );
            }

            return $blocks;
        }

        if ($contentType === 'computer_output' && ($screenshot = $content->nullFluent('screenshot')) !== null) {
            $attachment = $this->attachmentFromAssetPart($screenshot, AttachmentType::Image);

            $blocks[] = new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Image,
                structuredContent: $content->all(),
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

                $block = $this->parseMultimodalPart(new Fluent($part), $position, $contentType);

                if ($block !== null) {
                    $blocks[] = $block;
                    $position++;
                }
            }

            return $blocks;
        }

        $text = $this->contentText($content);

        if (! $this->isBlank($text, $content->all())) {
            $blocks[] = new CanonicalContentBlock(
                position: $position,
                blockType: $this->mapBlockTypeForContentType($contentType, $role),
                textContent: $text !== '' ? $text : null,
                structuredContent: $content->all(),
                metadata: ['content_type' => $contentType],
            );
        }

        return $blocks;
    }

    private function parseMultimodalPart(Fluent $part, int $position, string $contentType): ?CanonicalContentBlock
    {
        $assetType = $part->nullString('asset_type')
            ?? $part->nullString('type')
            ?? $part->nullString('content_type')
            ?? '';

        if (
            in_array($assetType, ['image', 'image_asset_pointer'], true)
            || $part->has('asset_pointer')
            || $part->has('image_url')
        ) {
            $url = $part->nullString('image_url.url')
                ?? $part->nullString('image_url')
                ?? '';
            $externalUrl = preg_match('/^https?:\/\//i', $url) === 1 ? $url : null;
            $attachment = $this->attachmentFromAssetPart($part, AttachmentType::Image, $externalUrl);

            return new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Image,
                textContent: $externalUrl,
                structuredContent: $part->all(),
                metadata: ['content_type' => $contentType],
                attachments: $attachment !== null ? [$attachment] : [],
            );
        }

        $text = $part->nullString('text') ?? $part->nullString('content');

        if ($text !== null && ! $this->isBlank($text, $part->all())) {
            return new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Text,
                textContent: $text,
                structuredContent: $part->all(),
                metadata: ['content_type' => $contentType],
            );
        }

        if (! $this->isBlank(null, $part->all())) {
            return new CanonicalContentBlock(
                position: $position,
                blockType: BlockType::Other,
                structuredContent: $part->all(),
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
                $part = new Fluent($part);
                $text = $part->nullString('text') ?? $part->nullString('content');

                if ($text !== null) {
                    $segments[] = $text;
                }
            }
        }

        return trim(implode("\n", $segments));
    }

    private function hasToolRecipient(Fluent $message): bool
    {
        $recipient = trim($message->nullString('recipient') ?? '');

        return $recipient !== '' && $recipient !== 'all';
    }

    private function contentText(Fluent $content): string
    {
        if (($text = $content->nullString('text')) !== null) {
            return trim($text);
        }

        if (($text = $content->nullString('content')) !== null) {
            return trim($text);
        }

        $parts = $content->array('parts');

        return $this->flattenParts($parts);
    }

    private function contentPayload(Fluent $content): mixed
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

    private function resolveCitations(string $text, Fluent $message): string
    {
        foreach ($message->collectFluent('metadata.content_references') as $reference) {
            if ($reference->nullString('type') === 'sources_footnote') {
                continue;
            }

            $matchedText = $reference->nullString('matched_text') ?? '';
            $alt = trim($reference->nullString('alt') ?? '');

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

    /** @return array<string, mixed> */
    private function conversationMetadata(Fluent $data): array
    {
        return $data->except(['mapping', 'title']);
    }

    /** @return array<string, mixed> */
    private function messageMetadata(Fluent $message): array
    {
        $metadata = $message->nullArray('metadata') ?? [];
        $source = $message->except(['content', 'metadata', 'id', 'create_time']);

        if ($source !== []) {
            $metadata['_source'] = $source;
        }

        return $metadata;
    }

    /** @return array<int, CanonicalAttachment> */
    private function parseMessageAttachments(Fluent $message): array
    {
        $attachments = [];

        foreach ($message->collectFluent('metadata.attachments') as $sourceAttachment) {
            $sourceId = $this->sourceAttachmentId(
                $sourceAttachment->get('id')
                ?? $sourceAttachment->get('file_id')
                ?? $sourceAttachment->get('library_file_id'),
            );

            if ($sourceId === null) {
                continue;
            }

            $mimeType = $sourceAttachment->nullString('mime_type')
                ?? $sourceAttachment->nullString('mimeType');
            $attachments[$sourceId] = new CanonicalAttachment(
                sourceAttachmentId: $sourceId,
                attachmentType: $this->attachmentType($mimeType),
                filename: ($name = $sourceAttachment->nullString('name')) !== null
                    ? Str::limit($name, 255, '')
                    : null,
                mimeType: $mimeType,
                byteSize: $sourceAttachment->nullInteger('size'),
                sourceRef: $sourceAttachment->all(),
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

    private function attachmentFromAssetPart(
        Fluent $part,
        AttachmentType $attachmentType,
        ?string $externalUrl = null,
    ): ?CanonicalAttachment {
        $pointer = $part->nullString('asset_pointer')
            ?? $part->nullString('image_url.url')
            ?? $part->nullString('image_url')
            ?? $externalUrl;

        $sourceId = $this->sourceAttachmentId($pointer);

        if ($sourceId === null && $externalUrl === null) {
            return null;
        }

        return new CanonicalAttachment(
            sourceAttachmentId: $sourceId,
            attachmentType: $attachmentType,
            byteSize: $part->nullInteger('size_bytes'),
            externalUrl: $externalUrl,
            sourceRef: $part->all(),
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

    /** @return array<int, array{key: string, value: string}> */
    private function assetReferences(Fluent $message): array
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

        $walk($message->all());

        return $references;
    }
}
