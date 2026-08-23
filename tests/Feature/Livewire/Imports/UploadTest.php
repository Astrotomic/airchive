<?php

namespace Tests\Feature\Livewire\Imports;

use App\Enums\ImportFormat;
use App\Jobs\ImportConversationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use ZipArchive;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_incomplete_json_without_creating_an_import(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $upload = UploadedFile::fake()->createWithContent(
            'truncated-conversation.json',
            '{"mapping":{"message":{"author',
        );

        Livewire::actingAs($user)
            ->test('imports.upload')
            ->set('upload', $upload)
            ->call('save')
            ->assertHasErrors(['upload'])
            ->assertSee('The uploaded JSON file is invalid or incomplete. Download or export it again, then retry.');

        $this->assertDatabaseCount('import_batches', 0);
        Storage::disk('local')->assertDirectoryEmpty('imports');
        Queue::assertNotPushed(ImportConversationJob::class);
    }

    public function test_accepts_and_detects_a_full_cursor_export_zip(): void
    {
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'cursor_upload_');
        Assert::assertNotFalse($path);
        $zip = new ZipArchive;
        Assert::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString(
            'workspace/agent-transcripts/00000000-0000-4000-8000-000000000000/00000000-0000-4000-8000-000000000000.jsonl',
            json_encode(['role' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'Hello']]]], JSON_THROW_ON_ERROR)."\n",
        );
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);
        Assert::assertIsString($contents);

        Livewire::actingAs($user)
            ->test('imports.upload')
            ->set('upload', UploadedFile::fake()->createWithContent('cursor-export.zip', $contents))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('import_batches', [
            'user_id' => $user->id,
            'detected_format' => ImportFormat::CursorExport->value,
        ]);
        Queue::assertPushed(ImportConversationJob::class);
    }
}
