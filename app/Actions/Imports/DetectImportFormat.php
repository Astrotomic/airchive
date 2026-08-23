<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use App\Enums\ImportFormat;
use Illuminate\Support\Str;
use ZipArchive;

class DetectImportFormat extends Action
{
    public function execute(string $path, ?string $displayName = null): ImportFormat
    {
        if (is_dir($path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && Str::endsWith(Str::lower($file->getPathname()), '.jsonl')
                    && Str::contains(str_replace('\\', '/', $file->getPathname()), '/agent-transcripts/')) {
                    return ImportFormat::CursorExport;
                }
            }

            throw new \InvalidArgumentException('The directory does not contain a Cursor agent-transcripts export.');
        }

        $name = $displayName ?? $path;

        if ($this->pathLooksLikeZip($path, $name)) {
            return $this->detectZipPath($path);
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new \RuntimeException('Import file could not be read: '.$path);
        }

        if ($this->isCursorJsonl($name, $contents)) {
            return ImportFormat::CursorJsonl;
        }

        if ($this->isChatGptJson($contents)) {
            return ImportFormat::ChatGptJson;
        }

        if (Str::endsWith(Str::lower($name), '.json') && ! json_validate($contents)) {
            throw new \InvalidArgumentException(
                'The uploaded JSON file is invalid or incomplete. Download or export it again, then retry.'
            );
        }

        throw new \InvalidArgumentException(
            'Unable to detect import format for file: '.$name
        );
    }

    private function pathLooksLikeZip(string $path, string $displayName): bool
    {
        if (Str::endsWith(Str::lower($displayName), '.zip')) {
            return true;
        }

        $stream = @fopen($path, 'rb');

        if ($stream === false) {
            return false;
        }

        try {
            return fread($stream, 4) === "PK\x03\x04";
        } finally {
            fclose($stream);
        }
    }

    private function detectZipPath(string $path): ImportFormat
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('The uploaded ZIP archive could not be opened.');
        }

        $hasCursorTranscript = false;
        $hasChatGptConversations = false;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));

                if (preg_match('~(?:^|/)agent-transcripts/.+\.jsonl$~i', $name) === 1) {
                    $hasCursorTranscript = true;
                }

                if (preg_match('~(?:^|/)conversations(?:-\d+)?\.json$~i', $name) === 1) {
                    $hasChatGptConversations = true;
                }
            }
        } finally {
            $zip->close();
        }

        if ($hasCursorTranscript) {
            return ImportFormat::CursorExport;
        }

        if ($hasChatGptConversations) {
            return ImportFormat::ChatGptZip;
        }

        throw new \InvalidArgumentException('The ZIP does not contain a supported ChatGPT or Cursor export.');
    }

    private function isCursorJsonl(string $filePath, string $contents): bool
    {
        if (Str::endsWith(Str::lower($filePath), '.jsonl')) {
            return true;
        }

        $lines = $this->nonEmptyLines($contents);

        if ($lines === []) {
            return false;
        }

        foreach (array_slice($lines, 0, 5) as $line) {
            $decoded = json_decode($line, true);

            if (! is_array($decoded)) {
                return false;
            }

            if (isset($decoded['mapping'], $decoded['current_node'])) {
                return false;
            }

            if (isset($decoded['role'], $decoded['message']['content']) && is_array($decoded['message']['content'])) {
                return true;
            }
        }

        return false;
    }

    private function isChatGptJson(string $contents): bool
    {
        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return false;
        }

        if (isset($decoded['mapping'], $decoded['current_node'])) {
            return true;
        }

        if (array_is_list($decoded) && isset($decoded[0]['mapping'], $decoded[0]['current_node'])) {
            return true;
        }

        return false;
    }

    /** @return array<int, string> */
    private function nonEmptyLines(string $contents): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\n|\r/', $contents) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
