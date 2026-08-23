<?php

namespace App\ValueObjects;

use App\Enums\MessageRole;
use Carbon\CarbonInterface;

final readonly class CanonicalMessage
{
    /**
     * @param  array<int, CanonicalContentBlock>  $blocks
     * @param  array<string, mixed>  $metadata
     * @param  array<int, CanonicalAttachment>  $attachments
     */
    public function __construct(
        public string $sourceMessageId,
        public ?string $parentSourceMessageId,
        public MessageRole $role,
        public ?string $actorName,
        public ?CarbonInterface $createdAt,
        public bool $isOnCanonicalPath,
        public bool $isHidden,
        public array $blocks,
        public array $metadata = [],
        public array $attachments = [],
    ) {}
}
