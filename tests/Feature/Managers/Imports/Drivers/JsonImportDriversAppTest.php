<?php

namespace Tests\Feature\Managers\Imports\Drivers;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Managers\Imports\Drivers\ChatGptJsonImportDriver;
use App\Managers\Imports\Drivers\CursorJsonlImportDriver;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\AppTestCase;

class JsonImportDriversAppTest extends AppTestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            File::delete($file);
        }

        foreach ($this->temporaryDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_chatgpt_json_driver_imports_a_real_file_and_records_its_source(): void
    {
        $contents = json_encode([
            'title' => 'Driver import',
            'conversation_id' => 'chatgpt-driver-1',
            'current_node' => 'message-1',
            'mapping' => [
                'message-1' => [
                    'parent' => null,
                    'message' => [
                        'author' => ['role' => 'user'],
                        'content' => ['content_type' => 'text', 'parts' => ['Hello']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $path = $this->temporaryFile('chatgpt-export.json', $contents);
        $batch = $this->batch('stored/import.json', ImportFormat::ChatGptJson);

        (new ChatGptJsonImportDriver)->import($batch, $path, hash('sha256', $contents));

        $conversation = Conversation::query()->sole();
        Assert::assertSame('Driver import', $conversation->title);
        Assert::assertSame('chatgpt-driver-1', $conversation->source_conversation_id);
        Assert::assertSame('chatgpt-export.json', $conversation->sources()->sole()->source_file);
        Assert::assertSame('stored/import.json', $conversation->sources()->sole()->raw_storage_path);
    }

    public function test_cursor_jsonl_driver_derives_workspace_and_subagent_metadata_from_path(): void
    {
        $uuid = '123e4567-e89b-12d3-a456-426614174000';
        $directory = $this->temporaryDirectory()
            .'/workspace-one/agent-transcripts/parent-transcript/subagents';
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.$uuid.'.jsonl';
        $contents = json_encode([
            'role' => 'user',
            'message' => ['content' => [['type' => 'text', 'text' => 'Cursor message']]],
        ], JSON_THROW_ON_ERROR);
        File::put($path, $contents);
        $this->temporaryFiles[] = $path;
        $batch = $this->batch('stored/transcript.jsonl', ImportFormat::CursorJsonl);

        (new CursorJsonlImportDriver)->import($batch, $path, hash('sha256', $contents));

        $conversation = Conversation::query()->sole();
        Assert::assertSame('workspace-one:parent-transcript:'.$uuid, $conversation->source_conversation_id);
        Assert::assertSame('workspace-one', $conversation->metadata['cursor_workspace']);
        Assert::assertTrue($conversation->metadata['cursor_is_subagent']);
        Assert::assertSame('parent-transcript', $conversation->metadata['cursor_parent_transcript_id']);
        Assert::assertSame('workspace-one', $conversation->projects()->sole()->sourceIdentifiers()->sole()->source_identifier);
    }

    public function test_cursor_jsonl_driver_uses_empty_metadata_for_a_standalone_file(): void
    {
        $contents = json_encode([
            'session_id' => 'standalone-session',
            'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Standalone']],
        ], JSON_THROW_ON_ERROR);
        $path = $this->temporaryFile('standalone.jsonl', $contents);
        $batch = $this->batch('stored/standalone.jsonl', ImportFormat::CursorJsonl);

        (new CursorJsonlImportDriver)->import($batch, $path, hash('sha256', $contents));

        $conversation = Conversation::query()->sole();
        Assert::assertSame('standalone-session', $conversation->source_conversation_id);
        Assert::assertSame([], $conversation->metadata);
        Assert::assertCount(0, $conversation->projects()->get());
    }

    #[DataProvider('drivers')]
    public function test_json_drivers_report_unreadable_files(string $driverClass): void
    {
        $batch = $this->batch('stored/missing', ImportFormat::ChatGptJson);
        $path = sys_get_temp_dir().'/airchive_missing_'.bin2hex(random_bytes(8));
        set_error_handler(static fn (): bool => true);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Import file could not be read: '.$path);

            (new $driverClass)->import($batch, $path, 'checksum');
        } finally {
            restore_error_handler();
        }
    }

    /** @return iterable<string, array{class-string}> */
    public static function drivers(): iterable
    {
        yield 'ChatGPT JSON' => [ChatGptJsonImportDriver::class];
        yield 'Cursor JSONL' => [CursorJsonlImportDriver::class];
    }

    private function batch(string $filePath, ImportFormat $format): ImportBatch
    {
        return ImportBatch::query()->create([
            'user_id' => User::factory()->create()->id,
            'status' => ImportBatchStatus::Pending,
            'file_path' => $filePath,
            'detected_format' => $format,
        ]);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/airchive_json_driver_'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($directory);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function temporaryFile(string $name, string $contents): string
    {
        $directory = $this->temporaryDirectory();
        $path = $directory.'/'.$name;
        File::put($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
