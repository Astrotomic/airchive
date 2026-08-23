<?php

namespace Tests\Feature\Actions\Imports;

use App\Actions\Imports\RunImportBatch;
use App\Contracts\ConversationImportDriver;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Managers\Imports\ConversationImportManager;
use App\Models\ImportBatch;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\AppTestCase;

class RunImportBatchAppTest extends AppTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_it_detects_checksums_and_completes_a_file_import_with_progress(): void
    {
        $path = $this->temporaryFile('{"mapping":{},"current_node":"node-1"}');
        $batch = $this->batch(filePath: 'unused.json');
        $progressMessages = [];
        $calls = 0;
        $this->recordImports(ImportFormat::ChatGptJson, function (
            ImportBatch $receivedBatch,
            string $receivedPath,
            string $checksum,
            ?callable $progress,
        ) use ($batch, $path, &$calls): void {
            $calls++;
            Assert::assertTrue($receivedBatch->is($batch));
            Assert::assertSame($path, $receivedPath);
            Assert::assertSame(hash_file('sha256', $path), $checksum);
            Assert::assertIsCallable($progress);
        });

        RunImportBatch::make()->execute(
            $batch,
            $path,
            static function (string $message) use (&$progressMessages): void {
                $progressMessages[] = $message;
            },
        );

        $batch->refresh();
        Assert::assertSame(ImportBatchStatus::Completed, $batch->status);
        Assert::assertSame(ImportFormat::ChatGptJson, $batch->detected_format);
        Assert::assertNotNull($batch->started_at);
        Assert::assertNotNull($batch->finished_at);
        Assert::assertNull($batch->error_message);
        Assert::assertSame(['Checksumming import…'], $progressMessages);
        Assert::assertSame(1, $calls);
    }

    public function test_it_uses_the_storage_path_and_pre_detected_format(): void
    {
        Storage::fake('local');
        Storage::put('imports/conversation.jsonl', "{\"role\":\"user\"}\n");
        $batch = $this->batch('imports/conversation.jsonl', ImportFormat::CursorJsonl);
        $expectedPath = Storage::path('imports/conversation.jsonl');
        $this->recordImports(ImportFormat::CursorJsonl, static function (
            ImportBatch $batch,
            string $path,
            string $checksum,
            ?callable $progress,
        ) use ($expectedPath): void {
            Assert::assertSame(ImportFormat::CursorJsonl, $batch->detected_format);
            Assert::assertSame($expectedPath, $path);
            Assert::assertSame(hash_file('sha256', $expectedPath), $checksum);
            Assert::assertNull($progress);
        });

        RunImportBatch::make()->execute($batch);

        Assert::assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
    }

    public function test_it_hashes_directory_contents_in_stable_relative_path_order(): void
    {
        $directory = $this->temporaryDirectory();
        $nested = $directory.'/nested';
        mkdir($nested);
        $this->temporaryPaths[] = $nested;
        $second = $nested.'/b.txt';
        file_put_contents($second, 'second');
        $this->temporaryPaths[] = $second;
        $first = $directory.'/a.txt';
        file_put_contents($first, 'first');
        $this->temporaryPaths[] = $first;
        $link = $directory.'/ignored-link';
        symlink($first, $link);
        $this->temporaryPaths[] = $link;
        $batch = $this->batch('unused', ImportFormat::CursorExport);
        $expected = hash_init('sha256');
        hash_update($expected, "a.txt\0");
        hash_update_file($expected, $first);
        hash_update($expected, "nested/b.txt\0");
        hash_update_file($expected, $second);
        $expectedChecksum = hash_final($expected);
        $this->recordImports(ImportFormat::CursorExport, static function (
            ImportBatch $batch,
            string $path,
            string $checksum,
            ?callable $progress,
        ) use ($expectedChecksum): void {
            Assert::assertDirectoryExists($path);
            Assert::assertSame($expectedChecksum, $checksum);
            Assert::assertNull($progress);
        });

        RunImportBatch::make()->execute($batch, $directory);

        Assert::assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
    }

    public function test_it_records_and_rethrows_import_failures(): void
    {
        $path = $this->temporaryFile('contents');
        $batch = $this->batch('unused', ImportFormat::ChatGptJson);
        $this->recordImports(ImportFormat::ChatGptJson, static function (): never {
            throw new RuntimeException('Import exploded.');
        });

        try {
            RunImportBatch::make()->execute($batch, $path);
            Assert::fail('Import exception was not rethrown.');
        } catch (RuntimeException $exception) {
            Assert::assertSame('Import exploded.', $exception->getMessage());
        }

        $batch->refresh();
        Assert::assertSame(ImportBatchStatus::Failed, $batch->status);
        Assert::assertSame('Import exploded.', $batch->error_message);
        Assert::assertNotNull($batch->started_at);
        Assert::assertNotNull($batch->finished_at);
    }

    public function test_it_fails_when_the_import_cannot_be_checksummed(): void
    {
        $path = sys_get_temp_dir().'/airchive_missing_'.bin2hex(random_bytes(8));
        $batch = $this->batch('unused', ImportFormat::ChatGptJson);
        $this->recordImports(ImportFormat::ChatGptJson, static function (): void {
            Assert::fail('The import driver was called without a checksum.');
        });
        set_error_handler(static fn (): bool => true);

        try {
            RunImportBatch::make()->execute($batch, $path);
            Assert::fail('Missing import file was checksummed.');
        } catch (RuntimeException $exception) {
            Assert::assertSame('Failed to checksum import.', $exception->getMessage());
        } finally {
            restore_error_handler();
        }

        $batch->refresh();
        Assert::assertSame(ImportBatchStatus::Failed, $batch->status);
        Assert::assertSame('Failed to checksum import.', $batch->error_message);
    }

    private function batch(string $filePath, ?ImportFormat $format = null): ImportBatch
    {
        return ImportBatch::query()->create([
            'user_id' => User::factory()->create()->id,
            'status' => ImportBatchStatus::Pending,
            'file_path' => $filePath,
            'detected_format' => $format,
        ]);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'airchive_import_');
        Assert::assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/airchive_import_'.bin2hex(random_bytes(8));
        mkdir($path);
        $this->temporaryPaths[] = $path;

        return $path;
    }

    private function recordImports(ImportFormat $format, Closure $callback): void
    {
        app(ConversationImportManager::class)->extend(
            $format->value,
            static fn (): RecordingImportDriver => new RecordingImportDriver($callback),
        );
    }
}

class RecordingImportDriver implements ConversationImportDriver
{
    public function __construct(private Closure $callback) {}

    public function import(
        ImportBatch $batch,
        string $sourcePath,
        string $checksum,
        ?callable $progress = null,
    ): void {
        ($this->callback)($batch, $sourcePath, $checksum, $progress);
    }
}
