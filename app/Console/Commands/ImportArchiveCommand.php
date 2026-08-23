<?php

namespace App\Console\Commands;

use App\Actions\Imports\RunImportBatch;
use App\Enums\ImportBatchStatus;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class ImportArchiveCommand extends Command
{
    protected $signature = 'archive:import
        {path : Path to a ChatGPT ZIP/JSON, Cursor export ZIP/directory, or Cursor JSONL}
        {--user= : User ID or email address that owns the imported data}
        {--retry= : Reuse an interrupted or failed import batch ID}';

    protected $description = 'Import an archive synchronously from a local file without using the queue';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('path'));

        if ($path === null) {
            $this->error('The import file does not exist or is not readable.');

            return self::FAILURE;
        }

        if (Str::length($path) > 255) {
            $this->error('The resolved import path is too long to store in the import history.');

            return self::FAILURE;
        }

        $retryBatch = $this->resolveRetryBatch($this->option('retry'), $path);

        if ($this->option('retry') !== null && $retryBatch === null) {
            return self::FAILURE;
        }

        $userIdentifier = $this->option('user');

        if ($userIdentifier === null && $retryBatch !== null) {
            $userIdentifier = (string) $retryBatch->user_id;
        }

        $user = $this->resolveUser($userIdentifier);

        if ($user === null) {
            return self::FAILURE;
        }

        if ($retryBatch !== null && $retryBatch->user_id !== $user->id) {
            $this->error('The selected retry batch belongs to a different user.');

            return self::FAILURE;
        }

        $batch = $retryBatch ?? ImportBatch::query()->create([
            'user_id' => $user->id,
            'status' => ImportBatchStatus::Pending,
            'file_path' => $path,
        ]);

        $this->components->info("Importing {$path}");
        $this->line("Owner: {$user->email}");
        $this->line("Import batch: {$batch->id}");

        $memoryReserve = str_repeat('x', 1024 * 1024);

        register_shutdown_function(function () use (&$memoryReserve, $batch): void {
            $error = error_get_last();

            if (! is_array($error) || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $memoryReserve = null;
            $message = (string) $error['message'];

            try {
                ImportBatch::query()->whereKey($batch->id)->update([
                    'status' => ImportBatchStatus::Failed,
                    'error_message' => $message,
                    'finished_at' => now(),
                ]);
            } catch (Throwable) {
                // The original fatal error is more useful than a shutdown cleanup failure.
            }

            fwrite(STDERR, PHP_EOL.'Import failed fatally: '.$message.PHP_EOL);
        });

        try {
            RunImportBatch::make()->execute(
                $batch,
                $path,
                fn (string $message) => $this->line($message),
            );
        } catch (Throwable $exception) {
            $this->newLine();
            $this->components->error('Import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $batch->refresh();
        $duration = $batch->started_at?->diffInSeconds($batch->finished_at, true);

        $this->newLine();
        $this->components->info('Import completed.');
        $this->line('Format: '.$batch->detected_format->label());

        if ($duration !== null) {
            $this->line('Duration: '.number_format($duration, 2).' seconds');
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $path): ?string
    {
        $resolved = realpath($path);

        if ($resolved === false || (! is_file($resolved) && ! is_dir($resolved)) || ! is_readable($resolved)) {
            return null;
        }

        return $resolved;
    }

    private function resolveRetryBatch(mixed $identifier, string $path): ?ImportBatch
    {
        if ($identifier === null) {
            return null;
        }

        if (! is_string($identifier) || ! ctype_digit($identifier)) {
            $this->error('The --retry option must contain an import batch ID.');

            return null;
        }

        $batch = ImportBatch::query()->find((int) $identifier);

        if ($batch === null) {
            $this->error("Import batch {$identifier} was not found.");

            return null;
        }

        if ($batch->status === ImportBatchStatus::Completed) {
            $this->error("Import batch {$identifier} is already completed.");

            return null;
        }

        if ($batch->file_path !== $path) {
            $this->error("Import batch {$identifier} belongs to a different file path.");

            return null;
        }

        return $batch;
    }

    private function resolveUser(mixed $identifier): ?User
    {
        if (is_string($identifier) && $identifier !== '') {
            $user = ctype_digit($identifier)
                ? User::query()->find((int) $identifier)
                : User::query()->where('email', Str::lower($identifier))->first();

            if ($user === null) {
                $this->error("No user found for '{$identifier}'.");
            }

            return $user;
        }

        $users = User::query()->limit(2)->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        if ($users->isEmpty()) {
            $this->error('No user exists. Create one first or pass --user after creating it.');
        } else {
            $this->error('Multiple users exist. Pass --user=<id-or-email> to select the owner.');
        }

        return null;
    }
}
