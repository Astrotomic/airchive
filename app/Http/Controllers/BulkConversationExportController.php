<?php

namespace App\Http\Controllers;

use App\Actions\Conversations\ExportConversations;
use App\Enums\ExportFormat;
use App\Enums\ExportMode;
use App\Enums\SourcePlatform;
use App\Models\Conversation;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class BulkConversationExportController
{
    public function __invoke(
        Request $request,
        Filesystem $filesystem,
    ): BinaryFileResponse {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:1000'],
            'platform' => ['nullable', Rule::enum(SourcePlatform::class)],
            'project' => ['nullable', 'regex:/^(none|[1-9][0-9]*)$/'],
            'mode' => ['required', Rule::enum(ExportMode::class)],
            'format' => ['required', Rule::enum(ExportFormat::class)],
        ]);
        $conversations = Conversation::query()
            ->whereBelongsTo($request->user())
            ->with('projects:id,name,emoji')
            ->search((string) ($validated['query'] ?? ''))
            ->forPlatform($validated['platform'] ?? null)
            ->forProject($validated['project'] ?? null)
            ->latestByMessage()
            ->get();

        if ($conversations->isEmpty()) {
            throw ValidationException::withMessages([
                'query' => 'No conversations match the selected search and filters.',
            ]);
        }

        $mode = ExportMode::from($validated['mode']);
        $format = ExportFormat::from($validated['format']);
        $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'airchive-export-'.str()->uuid();
        $zipPath = $temporaryDirectory.'.zip';

        try {
            ExportConversations::make()->execute(
                $conversations,
                $format,
                $temporaryDirectory,
                $mode->includesChats(),
                $mode->includesFiles(),
            );
            $this->createZip($temporaryDirectory, $zipPath);
            $filesystem->deleteDirectory($temporaryDirectory);

            return response()
                ->download(
                    $zipPath,
                    'airchive-export-'.now()->format('Y-m-d-His').'.zip',
                    ['Content-Type' => 'application/zip'],
                )
                ->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            $filesystem->deleteDirectory($temporaryDirectory);
            $filesystem->delete($zipPath);

            throw $exception;
        }
    }

    private function createZip(string $directory, string $zipPath): void
    {
        $zip = new ZipArchive;
        $root = realpath($directory) ?: $directory;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The temporary export ZIP could not be created.');
        }

        try {
            foreach (['chats', 'files'] as $folder) {
                if (is_dir($directory.DIRECTORY_SEPARATOR.$folder)) {
                    $zip->addEmptyDir($folder);
                }
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getRealPath();

                if ($path === false) {
                    continue;
                }

                $zip->addFile($path, str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1)));
            }
        } finally {
            if (! $zip->close()) {
                throw new RuntimeException('The temporary export ZIP could not be finalized.');
            }
        }
    }
}
