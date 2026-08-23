<?php

namespace App\ValueObjects;

final readonly class ConversationExportResult
{
    /**
     * @param  list<string>  $unavailableFiles
     */
    public function __construct(
        public int $chatCount,
        public int $fileCount,
        public array $unavailableFiles = [],
    ) {}
}
