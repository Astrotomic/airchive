<?php

namespace App\Jobs;

use App\Actions\Imports\RunImportBatch;
use App\Models\ImportBatch;
use Illuminate\Bus\Queueable as QueueableByBus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportConversationJob implements ShouldQueue
{
    use InteractsWithQueue, QueueableByBus, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $importBatchId,
    ) {}

    public function handle(): void
    {
        $batch = ImportBatch::query()->findOrFail($this->importBatchId);

        RunImportBatch::make()->execute($batch);
    }
}
