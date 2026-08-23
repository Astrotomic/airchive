<?php

namespace App\ValueObjects;

use App\Enums\AttachmentType;

final readonly class CanonicalAttachment
{
    /**
     * @param  array<string, mixed>|null  $sourceRef
     */
    public function __construct(
        public AttachmentType $attachmentType,
        public ?string $sourceAttachmentId = null,
        public ?string $filename = null,
        public ?string $mimeType = null,
        public ?int $byteSize = null,
        public ?string $checksum = null,
        public ?string $storagePath = null,
        public ?string $externalUrl = null,
        public ?array $sourceRef = null,
    ) {}
}
