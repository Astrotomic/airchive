<?php

namespace App\ValueObjects;

use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;

final readonly class ProjectIdentifier
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public SourcePlatform $sourcePlatform,
        public ProjectIdentifierType $identifierType,
        public string $sourceIdentifier,
        public ?string $suggestedName = null,
        public array $metadata = [],
    ) {}
}
