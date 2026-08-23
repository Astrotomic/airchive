<?php

namespace App\ValueObjects;

use App\Enums\ImportFormat;

final readonly class CanonicalConversationSource
{
    public function __construct(
        public string $sourceFile,
        public ImportFormat $sourceFormat,
        public string $rawChecksum,
        public string $rawStoragePath,
    ) {}

    public static function fromImportContext(
        ImportContext $context,
        ?string $defaultSourceFile = null,
    ): self {
        return new self(
            sourceFile: $context->sourceFile ?? $defaultSourceFile ?? basename($context->filePath),
            sourceFormat: $context->sourceFormat,
            rawChecksum: $context->rawChecksum,
            rawStoragePath: $context->filePath,
        );
    }
}
