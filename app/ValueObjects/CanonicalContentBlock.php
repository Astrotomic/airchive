<?php

namespace App\ValueObjects;

use App\Enums\BlockType;

final readonly class CanonicalContentBlock
{
    /**
     * @param  array<string, mixed>|null  $structuredContent
     * @param  array<string, mixed>  $metadata
     * @param  array<int, CanonicalAttachment>  $attachments
     */
    public function __construct(
        public int $position,
        public BlockType $blockType,
        public ?string $textContent = null,
        public ?array $structuredContent = null,
        public ?string $language = null,
        public array $metadata = [],
        public array $attachments = [],
    ) {}
}
