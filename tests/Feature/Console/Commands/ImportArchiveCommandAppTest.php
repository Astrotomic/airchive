<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\ImportBatchStatus;
use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;
use ZipArchive;

class ImportArchiveCommandAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_imports_a_local_chatgpt_zip_synchronously(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'airchive_cli_');
        Assert::assertNotFalse($path);

        $zip = new ZipArchive;
        Assert::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('export/conversations.json', json_encode([[
            'id' => 'cli-conversation',
            'conversation_id' => 'cli-conversation',
            'title' => 'CLI import',
            'current_node' => 'message-1',
            'mapping' => [
                'message-1' => [
                    'id' => 'message-1',
                    'parent' => null,
                    'children' => [],
                    'message' => [
                        'id' => 'message-1',
                        'author' => ['role' => 'user'],
                        'create_time' => 1_700_000_000,
                        'content' => [
                            'content_type' => 'text',
                            'parts' => ['Imported directly from a local ZIP.'],
                        ],
                        'metadata' => [],
                    ],
                ],
            ],
        ]], JSON_THROW_ON_ERROR));
        $zip->close();

        try {
            $this->artisan('archive:import', [
                'path' => $path,
                '--user' => $user->email,
            ])
                ->expectsOutputToContain('Import completed.')
                ->assertSuccessful();

            $batch = ImportBatch::query()->sole();
            $batch->update(['status' => 'processing', 'finished_at' => null]);

            $this->artisan('archive:import', [
                'path' => $path,
                '--retry' => (string) $batch->id,
            ])
                ->expectsOutputToContain('Import completed.')
                ->assertSuccessful();
        } finally {
            unlink($path);
        }

        $batch = ImportBatch::query()->sole();

        Assert::assertSame(ImportBatchStatus::Completed, $batch->status);
        Assert::assertSame($path, $batch->file_path);
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('conversation_sources', 1);
        Assert::assertSame(
            SourcePlatform::ChatGpt,
            Conversation::query()->withoutGlobalScopes()->sole()->source_platform,
        );
    }

    public function test_requires_a_user_choice_when_multiple_users_exist(): void
    {
        User::factory()->count(2)->create();
        $path = tempnam(sys_get_temp_dir(), 'airchive_cli_');
        Assert::assertNotFalse($path);

        try {
            $this->artisan('archive:import', ['path' => $path])
                ->expectsOutputToContain('Multiple users exist.')
                ->assertFailed();
        } finally {
            unlink($path);
        }

        $this->assertDatabaseCount('import_batches', 0);
    }
}
