<?php

namespace Tests\Feature\Jobs;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Jobs\ImportConversationJob;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ImportConversationJobAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_runs_a_real_import_batch(): void
    {
        Storage::fake('local');
        $contents = json_encode([
            'title' => 'Queued import',
            'conversation_id' => 'queued-conversation',
            'current_node' => 'message-1',
            'mapping' => [
                'message-1' => [
                    'parent' => null,
                    'message' => [
                        'author' => ['role' => 'user'],
                        'content' => ['content_type' => 'text', 'parts' => ['Queued']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        Storage::put('imports/queued.json', $contents);
        $batch = ImportBatch::query()->create([
            'user_id' => User::factory()->create()->id,
            'status' => ImportBatchStatus::Pending,
            'file_path' => 'imports/queued.json',
            'detected_format' => ImportFormat::ChatGptJson,
        ]);
        $job = new ImportConversationJob($batch->id);

        $job->handle();

        Assert::assertSame(3600, $job->timeout);
        Assert::assertSame(1, $job->tries);
        Assert::assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
        Assert::assertSame('Queued import', Conversation::query()->sole()->title);
    }

    public function test_it_fails_when_the_batch_no_longer_exists(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new ImportConversationJob(999_999))->handle();
    }
}
