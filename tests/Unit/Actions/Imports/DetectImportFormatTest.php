<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\DetectImportFormat;
use App\Enums\ImportFormat;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\UnitTestCase;
use ZipArchive;

class DetectImportFormatTest extends UnitTestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_detects_cursor_export_directory(): void
    {
        $directory = $this->temporaryDirectory();
        $transcripts = $directory.'/agent-transcripts';
        mkdir($transcripts);
        $this->temporaryPaths[] = $transcripts;
        $transcript = $transcripts.'/conversation.JSONL';
        file_put_contents($transcript, '{}');
        $this->temporaryPaths[] = $transcript;

        Assert::assertSame(ImportFormat::CursorExport, DetectImportFormat::make()->execute($directory));
    }

    public function test_rejects_directory_without_cursor_transcripts(): void
    {
        $directory = $this->temporaryDirectory();
        $file = $directory.'/conversation.jsonl';
        file_put_contents($file, '{}');
        $this->temporaryPaths[] = $file;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The directory does not contain a Cursor agent-transcripts export.');

        DetectImportFormat::make()->execute($directory);
    }

    public function test_cursor_transcript_takes_precedence_in_mixed_zip(): void
    {
        $path = $this->temporaryZip([
            'archive/conversations.json' => '[]',
            'archive/agent-transcripts/conversation.jsonl' => '{}',
        ]);

        Assert::assertSame(ImportFormat::CursorExport, DetectImportFormat::make()->execute($path, 'export.zip'));
    }

    public function test_detects_chatgpt_zip_by_magic_bytes_and_sharded_filename(): void
    {
        $path = $this->temporaryZip(['nested/conversations-2.json' => '[]']);

        Assert::assertSame(ImportFormat::ChatGptZip, DetectImportFormat::make()->execute($path, 'export.bin'));
    }

    public function test_rejects_an_invalid_zip(): void
    {
        $path = $this->temporaryFile('not a zip');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The uploaded ZIP archive could not be opened.');

        DetectImportFormat::make()->execute($path, 'export.zip');
    }

    public function test_rejects_a_zip_without_a_supported_export(): void
    {
        $path = $this->temporaryZip(['README.txt' => 'Nothing to import']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The ZIP does not contain a supported ChatGPT or Cursor export.');

        DetectImportFormat::make()->execute($path, 'export.zip');
    }

    public function test_detects_cursor_jsonl_by_display_name(): void
    {
        $path = $this->temporaryFile('not valid JSONL');

        Assert::assertSame(ImportFormat::CursorJsonl, DetectImportFormat::make()->execute($path, 'conversation.JSONL'));
    }

    public function test_detects_cursor_jsonl_by_content(): void
    {
        $path = $this->temporaryFile("\n".json_encode([
            'role' => 'user',
            'message' => ['content' => [['type' => 'text', 'text' => 'Hello']]],
        ]));

        Assert::assertSame(ImportFormat::CursorJsonl, DetectImportFormat::make()->execute($path, 'conversation.txt'));
    }

    public function test_detects_single_chatgpt_conversation_json(): void
    {
        $path = $this->temporaryFile(json_encode([
            'mapping' => [],
            'current_node' => 'node-1',
        ]));

        Assert::assertSame(ImportFormat::ChatGptJson, DetectImportFormat::make()->execute($path, 'conversation.json'));
    }

    public function test_detects_chatgpt_conversation_list_json(): void
    {
        $path = $this->temporaryFile(json_encode([[
            'mapping' => [],
            'current_node' => 'node-1',
        ]]));

        Assert::assertSame(ImportFormat::ChatGptJson, DetectImportFormat::make()->execute($path, 'conversations.json'));
    }

    public function test_reports_truncated_json_as_invalid_or_incomplete(): void
    {
        $path = $this->temporaryFile('{"mapping":{"message":{"author');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The uploaded JSON file is invalid or incomplete. Download or export it again, then retry.'
        );

        DetectImportFormat::make()->execute($path, 'truncated-conversation.json');
    }

    public function test_rejects_valid_but_unknown_json(): void
    {
        $path = $this->temporaryFile('{"other":true}');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to detect import format for file: export.json');

        DetectImportFormat::make()->execute($path, 'export.json');
    }

    public function test_rejects_empty_and_malformed_jsonl_content(): void
    {
        foreach ([" \n\r", "not-json\n".json_encode([
            'role' => 'user',
            'message' => ['content' => []],
        ])] as $contents) {
            $path = $this->temporaryFile($contents);

            try {
                DetectImportFormat::make()->execute($path, 'export.txt');
                Assert::fail('Unknown content was accepted.');
            } catch (InvalidArgumentException $exception) {
                Assert::assertSame('Unable to detect import format for file: export.txt', $exception->getMessage());
            }
        }
    }

    public function test_reports_an_unreadable_import_file(): void
    {
        $path = sys_get_temp_dir().'/airchive_missing_'.bin2hex(random_bytes(8));
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Import file could not be read: '.$path);

            DetectImportFormat::make()->execute($path, 'export.txt');
        } finally {
            restore_error_handler();
        }
    }

    private function temporaryFile(string|false $contents): string
    {
        Assert::assertIsString($contents);
        $path = tempnam(sys_get_temp_dir(), 'airchive_detect_');
        Assert::assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/airchive_detect_'.bin2hex(random_bytes(8));
        mkdir($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    /** @param array<string, string> $entries */
    private function temporaryZip(array $entries): string
    {
        $path = $this->temporaryFile('');
        $zip = new ZipArchive;
        Assert::assertTrue($zip->open($path, ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            Assert::assertTrue($zip->addFromString($name, $contents));
        }

        Assert::assertTrue($zip->close());

        return $path;
    }
}
