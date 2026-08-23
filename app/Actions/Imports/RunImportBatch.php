<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use App\Enums\ImportBatchStatus;
use App\Managers\Imports\ConversationImportManager;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RunImportBatch extends Action
{
    public function __construct(
        private ConversationImportManager $imports,
    ) {}

    public function execute(
        ImportBatch $batch,
        ?string $sourcePath = null,
        ?callable $progress = null,
    ): void {
        $sourcePath ??= Storage::path($batch->file_path);

        $batch->update([
            'status' => ImportBatchStatus::Processing,
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ]);

        try {
            $format = $batch->detected_format;

            if ($format === null) {
                $format = DetectImportFormat::make()->execute($sourcePath);
            }

            $batch->update(['detected_format' => $format]);

            if ($progress !== null) {
                $progress('Checksumming import…');
            }

            $checksum = is_dir($sourcePath)
                ? $this->hashDirectory($sourcePath)
                : hash_file('sha256', $sourcePath);

            if (! is_string($checksum)) {
                throw new RuntimeException('Failed to checksum import.');
            }

            $this->imports->import($batch, $sourcePath, $checksum, $progress);

            $batch->update([
                'status' => ImportBatchStatus::Completed,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $batch->update([
                'status' => ImportBatchStatus::Failed,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function hashDirectory(string $sourcePath): string
    {
        $root = rtrim((string) realpath($sourcePath), DIRECTORY_SEPARATOR);
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files, SORT_STRING);
        $hash = hash_init('sha256');

        foreach ($files as $file) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
            hash_update($hash, $relative."\0");
            hash_update_file($hash, $file);
        }

        return hash_final($hash);
    }
}
