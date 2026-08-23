<?php

namespace Tests\Feature\Managers\Imports\Drivers;

use App\Enums\AttachmentType;
use App\Enums\ImportFormat;
use App\Enums\SourcePlatform;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\Message;
use App\Models\User;
use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Astrotomic\PhpunitAssertions\PathAssertions;
use Astrotomic\PhpunitAssertions\UuidAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use ZipArchive;

class CursorExportImportDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_full_cursor_zip_with_linked_and_library_artifacts(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'cursor_export_');
        Assert::assertNotFalse($path);

        $zip = new ZipArchive;
        Assert::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($this->transcriptEntry(), $this->transcript());
        $zip->addFromString('empty-window/plan.md', "# Imported plan\n");
        $zip->addFromString('empty-window/canvases/plan.canvas.tsx', 'export default function Plan() {}');
        $zip->addFromString('empty-window/assets/unlinked.png', "\x89PNG\r\n\x1a\n");
        $zip->addFromString('empty-window/mcps/user-Test/SERVER_METADATA.json', '{}');
        $zip->addFromString('__MACOSX/empty-window/._plan.md', 'noise');
        $zip->close();

        try {
            $this->artisan('archive:import', ['path' => $path, '--user' => $user->email])
                ->expectsOutputToContain('Imported 1 Cursor conversations.')
                ->expectsOutputToContain('Imported 3 Cursor workspace artifacts.')
                ->assertSuccessful();
        } finally {
            unlink($path);
        }

        $batch = ImportBatch::query()->sole();
        $conversation = Conversation::query()->withoutGlobalScopes()->sole();
        $sourceConversationId = (string) $conversation->source_conversation_id;

        Assert::assertSame(ImportFormat::CursorExport, $batch->detected_format);
        Assert::assertSame(SourcePlatform::Cursor, $conversation->source_platform);
        UuidAssertions::assertUuid(substr($sourceConversationId, strlen('empty-window:')));
        Assert::assertSame('empty-window:1b607d10-dc89-4ea2-bbfe-c630b3475886', $sourceConversationId);
        Assert::assertSame('Build an import plan', $conversation->title);
        Assert::assertSame('empty-window', $conversation->metadata['cursor_workspace']);
        Assert::assertSame('Cursor · Empty Window', $conversation->projects()->sole()->name);
        Assert::assertSame('empty-window', $conversation->projects()->sole()->sourceIdentifiers()->sole()->source_identifier);
        $this->assertDatabaseCount('attachments', 3);

        $linked = Attachment::query()->whereNotNull('conversation_id')->get();
        $unlinked = Attachment::query()->whereNull('conversation_id')->sole();

        Assert::assertCount(2, $linked);
        PathAssertions::assertExtension('png', $unlinked->filename);
        Assert::assertSame('unlinked.png', $unlinked->filename);
        $attachmentTypes = $linked->pluck('attachment_type')
            ->sortBy(fn (AttachmentType $type): string => $type->value)
            ->values()
            ->all();
        ArrayAssertions::assertIndexed($attachmentTypes);
        Assert::assertSame(
            [AttachmentType::Canvas, AttachmentType::File],
            $attachmentTypes,
        );
        Assert::assertSame(
            Message::query()->where('source_message_id', 'line-2')->value('id'),
            $linked->firstWhere('filename', 'plan.md')?->message_id,
        );

        foreach (Attachment::all() as $attachment) {
            Storage::assertExists($attachment->storage_path);
        }

        $this->assertDatabaseMissing('attachments', ['filename' => 'SERVER_METADATA.json']);
    }

    public function test_imports_full_cursor_directory(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $root = sys_get_temp_dir().'/cursor_directory_'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists(dirname($root.'/'.$this->transcriptEntry()));
        File::put($root.'/'.$this->transcriptEntry(), $this->transcript());
        File::put($root.'/empty-window/plan.md', '# Directory plan');

        try {
            $this->artisan('archive:import', ['path' => $root, '--user' => $user->email])
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($root);
        }

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('attachments', 1);
        Assert::assertNotNull(Attachment::query()->sole()->message_id);
    }

    public function test_imports_single_cursor_jsonl_with_same_stable_conversation_id(): void
    {
        $user = User::factory()->create();
        $root = sys_get_temp_dir().'/cursor_single_'.bin2hex(random_bytes(6));
        $id = '1b607d10-dc89-4ea2-bbfe-c630b3475886';
        $path = "{$root}/empty-window/agent-transcripts/{$id}/{$id}.jsonl";
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->transcript());

        try {
            $this->artisan('archive:import', ['path' => $path, '--user' => $user->email])
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($root);
        }

        $conversation = Conversation::query()->withoutGlobalScopes()->sole();
        $sourceConversationId = (string) $conversation->source_conversation_id;
        UuidAssertions::assertUuid(substr($sourceConversationId, strlen('empty-window:')));
        Assert::assertSame('empty-window:1b607d10-dc89-4ea2-bbfe-c630b3475886', $sourceConversationId);
        Assert::assertSame(ImportFormat::CursorJsonl, ImportBatch::query()->sole()->detected_format);
    }

    private function transcriptEntry(): string
    {
        $id = '1b607d10-dc89-4ea2-bbfe-c630b3475886';

        return "empty-window/agent-transcripts/{$id}/{$id}.jsonl";
    }

    private function transcript(): string
    {
        return implode("\n", [
            json_encode(['role' => 'user', 'message' => ['content' => [[
                'type' => 'text',
                'text' => "<user_query>\nBuild an import plan\n</user_query>",
            ]]]], JSON_THROW_ON_ERROR),
            json_encode(['role' => 'assistant', 'message' => ['content' => [
                ['type' => 'text', 'text' => "Planning\u{2028}artifacts"],
                [
                    'type' => 'tool_use',
                    'name' => 'Write',
                    'input' => [
                        'path' => '/Users/test/.cursor/projects/empty-window/plan.md',
                        'contents' => '# Imported plan',
                    ],
                ],
                [
                    'type' => 'tool_use',
                    'name' => 'Write',
                    'input' => [
                        'path' => '/Users/test/.cursor/projects/empty-window/canvases/plan.canvas.tsx',
                        'contents' => 'export default function Plan() {}',
                    ],
                ],
            ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS),
        ])."\n";
    }
}
