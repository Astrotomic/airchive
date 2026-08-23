<?php

namespace App\ValueObjects;

use App\Enums\ImportFormat;

final readonly class ImportContext
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $userId,
        public string $filePath,
        public ImportFormat $sourceFormat,
        public string $rawChecksum,
        public ?string $sourceFile = null,
        public array $metadata = [],
    ) {}
}
