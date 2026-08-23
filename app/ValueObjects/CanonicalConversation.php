<?php

namespace App\ValueObjects;

use App\Enums\SourcePlatform;

final readonly class CanonicalConversation
{
    /**
     * @param  array<int, CanonicalMessage>  $messages
     * @param  array<string, mixed>  $metadata
     * @param  array<int, CanonicalConversationSource>  $sources
     * @param  array<int, ProjectIdentifier>  $projectIdentifiers
     */
    public function __construct(
        public string $title,
        public SourcePlatform $sourcePlatform,
        public string $sourceConversationId,
        public array $messages,
        public array $metadata = [],
        public array $sources = [],
        public ?string $canonicalLeafSourceMessageId = null,
        public array $projectIdentifiers = [],
    ) {}
}
