<?php

namespace App\Contracts;

use App\Models\ImportBatch;

interface ConversationImportDriver
{
    public function import(
        ImportBatch $batch,
        string $sourcePath,
        string $checksum,
        ?callable $progress = null,
    ): void;
}
