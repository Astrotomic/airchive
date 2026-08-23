<?php

namespace Tests\Feature\Managers\Imports\Drivers;

use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use App\Enums\SourcePlatform;
use App\Jobs\ImportConversationJob;
use App\Managers\Imports\Drivers\ChatGptZipImportDriver;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\ImportBatch;
use App\Models\User;
use Astrotomic\PhpunitAssertions\PathAssertions;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Assert;
use Tests\TestCase;
use ZipArchive;

class ChatGptZipImportDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_streams_multiple_conversations_from_a_shard(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $conversations = [];

        foreach (range(1, 250) as $index) {
            $conversations[] = [
                'id' => 'conversation-'.$index,
                'current_node' => 'message-'.$index,
                'mapping' => [
                    'message-'.$index => [
                        'id' => 'message-'.$index,
                        'message' => null,
                        'parent' => null,
                        'children' => [],
                    ],
                ],
                'metadata' => ['escaped' => 'quote: " slash: \\ braces: {[]}'],
            ];
        }

        $zipPath = $this->createZip([
            'export/conversations-000.json' => json_encode($conversations, JSON_THROW_ON_ERROR),
        ]);

        $batch = ImportBatch::query()->create([
            'user_id' => $user->id,
            'status' => ImportBatchStatus::Pending,
            'file_path' => $zipPath,
            'detected_format' => ImportFormat::ChatGptZip,
        ]);

        app(ChatGptZipImportDriver::class)->import(
            $batch,
            $zipPath,
            hash_file('sha256', $zipPath),
        );

        Assert::assertSame(250, Conversation::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseHas('conversations', ['source_conversation_id' => 'conversation-1']);
        $this->assertDatabaseHas('conversations', ['source_conversation_id' => 'conversation-250']);
    }

    public function test_imports_sharded_conversations_message_attachments_images_and_library_files(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $zipPath = $this->createZip([
            'export/conversations-000.json' => json_encode([[
                'id' => 'conversation-1',
                'conversation_id' => 'conversation-1',
                'title' => 'Files and images',
                'current_node' => 'message-1',
                'default_model_slug' => 'gpt-test',
                'mapping' => [
                    'message-1' => [
                        'id' => 'message-1',
                        'parent' => null,
                        'children' => [],
                        'message' => [
                            'id' => 'message-1',
                            'author' => ['role' => 'user', 'name' => null],
                            'create_time' => 1_700_000_000,
                            'status' => 'finished_successfully',
                            'content' => [
                                'content_type' => 'multimodal_text',
                                'parts' => [
                                    'Please inspect these files.',
                                    [
                                        'content_type' => 'image_asset_pointer',
                                        'asset_pointer' => 'file-service://file-image123',
                                        'size_bytes' => 9,
                                        'width' => 10,
                                        'height' => 20,
                                        'metadata' => [],
                                    ],
                                ],
                            ],
                            'metadata' => [
                                'model_slug' => 'gpt-test',
                                'attachments' => [
                                    [
                                        'id' => 'file-image123',
                                        'name' => 'picture.png',
                                        'mime_type' => 'image/png',
                                        'size' => 9,
                                    ],
                                    [
                                        'id' => 'file-plan123',
                                        'name' => 'plan.md',
                                        'mime_type' => 'text/markdown',
                                        'size' => 13,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]], JSON_THROW_ON_ERROR),
            'export/export_manifest.json' => json_encode([
                'version' => 1,
                'manifest_file' => 'export_manifest.json',
                'export_files' => [],
                'logical_files' => [
                    'conversations-000.json' => ['files' => ['conversations-000.json'], 'sharded' => false],
                    'file-image123.dat' => ['files' => ['file-image123.dat'], 'sharded' => false],
                    'file-plan123.dat' => ['files' => ['file-plan123.dat'], 'sharded' => false],
                    'file-library123.dat' => ['files' => ['file-library123.dat'], 'sharded' => false],
                    'file-orphan123.dat' => ['files' => ['file-orphan123.dat'], 'sharded' => false],
                ],
            ], JSON_THROW_ON_ERROR),
            'export/conversation_asset_file_names.json' => json_encode([
                'file-image123.dat' => 'picture.png',
                'file-plan123.dat' => 'plan.md',
                'file-library123.dat' => 'library.json',
                'file-orphan123.dat' => 'orphan.txt',
            ], JSON_THROW_ON_ERROR),
            'export/library_files.json' => json_encode([[
                'id' => 'library-record-1',
                'file_id' => 'file-library123',
                'file_name' => 'library.json',
                'mime_type' => 'application/json',
                'file_size_bytes' => 7,
                'origination_message_id' => null,
                'origination_thread_id' => null,
            ]], JSON_THROW_ON_ERROR),
            'export/shared_conversations.json' => json_encode([[
                'id' => 'share-1',
                'conversation_id' => 'conversation-1',
                'title' => 'Shared files and images',
                'is_anonymous' => false,
            ]], JSON_THROW_ON_ERROR),
            'export/message_feedback.json' => json_encode([[
                'id' => 'feedback-1',
                'conversation_id' => 'conversation-1',
                'rating' => 'thumbsUp',
                'content' => 'Helpful',
            ]], JSON_THROW_ON_ERROR),
            'export/file-image123.dat' => 'PNG bytes',
            'export/file-plan123.dat' => "# Test plan\n",
            'export/file-library123.dat' => '{"a":1}',
            'export/file-orphan123.dat' => 'orphaned export asset',
            '__MACOSX/export/._conversations-000.json' => 'ignored',
        ]);

        Storage::put('imports/export.zip', fopen($zipPath, 'rb'));

        $batch = ImportBatch::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'file_path' => 'imports/export.zip',
            'detected_format' => ImportFormat::ChatGptZip,
        ]);

        app()->call([new ImportConversationJob($batch->id), 'handle']);

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('source_platform', SourcePlatform::ChatGpt)
            ->sole();
        $sourceUrl = $conversation->metadata['shared_conversations'][0]['source_url'];

        Assert::assertSame('gpt-test', $conversation->metadata['default_model_slug']);
        UrlAssertions::assertValidLoose($sourceUrl);
        Assert::assertSame(
            'https://chatgpt.com/share/share-1',
            $sourceUrl,
        );
        Assert::assertSame('thumbsUp', $conversation->metadata['message_feedback'][0]['rating']);
        Assert::assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('attachments', 4);

        $image = Attachment::query()->where('source_attachment_id', 'file-image123')->sole();
        Assert::assertSame(AttachmentType::Image, $image->attachment_type);
        PathAssertions::assertExtension('png', $image->filename);
        Assert::assertSame('picture.png', $image->filename);
        Assert::assertNotNull($image->content_block_id);
        Assert::assertSame('PNG bytes', Storage::get($image->storage_path));

        $plan = Attachment::query()->where('source_attachment_id', 'file-plan123')->sole();
        PathAssertions::assertExtension('md', $plan->filename);
        Assert::assertSame('plan.md', $plan->filename);
        Assert::assertSame('text/markdown', $plan->mime_type);
        Assert::assertSame("# Test plan\n", Storage::get($plan->storage_path));

        $libraryFile = Attachment::query()->where('source_attachment_id', 'file-library123')->sole();
        Assert::assertNull($libraryFile->message_id);
        Assert::assertNull($libraryFile->conversation_id);
        Assert::assertSame($user->id, $libraryFile->user_id);
        Assert::assertSame('{"a":1}', Storage::get($libraryFile->storage_path));

        $orphan = Attachment::query()->where('source_attachment_id', 'file-orphan123')->sole();
        Assert::assertNull($orphan->message_id);
        Assert::assertTrue($orphan->source_ref['archive_unlinked']);
        Assert::assertSame('orphaned export asset', Storage::get($orphan->storage_path));

        Assert::assertTrue(ContentBlock::query()->where('block_type', BlockType::Image)->exists());
        Assert::assertSame(
            'finished_successfully',
            $conversation->messages()->sole()->metadata['_source']['status'],
        );
    }

    public function test_resolves_legacy_assets_with_filename_in_the_archive_entry(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $zipPath = $this->createZip([
            'export/conversations.json' => json_encode([], JSON_THROW_ON_ERROR),
            'export/export_manifest.json' => json_encode([
                'logical_files' => [
                    'file-legacy123-original.png' => [
                        'files' => ['file-legacy123-original.png'],
                        'sharded' => false,
                    ],
                    'render#file_legacyPdf123#p_0.jpg-p_0.jpg' => [
                        'files' => ['render#file_legacyPdf123#p_0.jpg-p_0.jpg'],
                        'sharded' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
            'export/file-legacy123-original.png' => 'legacy image',
            'export/render#file_legacyPdf123#p_0.jpg-p_0.jpg' => 'rendered page',
        ]);

        $batch = ImportBatch::query()->create([
            'user_id' => $user->id,
            'status' => ImportBatchStatus::Pending,
            'file_path' => $zipPath,
            'detected_format' => ImportFormat::ChatGptZip,
        ]);

        app(ChatGptZipImportDriver::class)->import($batch, $zipPath, 'export-checksum');

        $legacy = Attachment::query()->where('source_attachment_id', 'file-legacy123')->sole();
        PathAssertions::assertExtension('png', $legacy->filename);
        Assert::assertSame('original.png', $legacy->filename);
        Assert::assertSame('image/png', $legacy->mime_type);
        Assert::assertSame('legacy image', Storage::get($legacy->storage_path));

        $derived = Attachment::query()->where('source_attachment_id', 'like', 'archive:%')->sole();
        Assert::assertSame('image/jpeg', $derived->mime_type);
        Assert::assertSame('rendered page', Storage::get($derived->storage_path));
    }

    public function test_imports_codex_conversations_bundled_with_chatgpt_export(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $zipPath = $this->createZip([
            'export/conversations-000.json' => '[]',
            'export/codex.json' => json_encode([[
                'id' => 'codex-conversation-1',
                'title' => 'Codex task',
                'archived' => false,
                'turns' => [
                    [
                        'id' => 'turn-user',
                        'role' => 'user',
                        'custom_instructions' => null,
                        'input_items' => [[
                            'type' => 'message',
                            'role' => 'user',
                            'content' => [[
                                'content_type' => 'text',
                                'text' => 'Build the feature.',
                            ]],
                        ]],
                    ],
                    [
                        'id' => 'turn-assistant',
                        'previous_turn_id' => 'turn-user',
                        'role' => 'assistant',
                        'turn_status' => 'completed',
                        'output_items' => [
                            [
                                'type' => 'message',
                                'role' => 'assistant',
                                'content' => [[
                                    'content_type' => 'text',
                                    'text' => 'Implemented.',
                                ]],
                            ],
                            [
                                'type' => 'image_asset_pointer',
                                'asset_pointer' => 'sediment://file_codex123',
                                'size_bytes' => 11,
                                'width' => 100,
                                'height' => 50,
                            ],
                        ],
                    ],
                ],
            ]], JSON_THROW_ON_ERROR),
            'export/export_manifest.json' => json_encode([
                'logical_files' => [
                    'file_codex123.dat' => ['files' => ['file_codex123.dat'], 'sharded' => false],
                ],
            ], JSON_THROW_ON_ERROR),
            'export/conversation_asset_file_names.json' => json_encode([
                'file_codex123.dat' => 'codex.png',
            ], JSON_THROW_ON_ERROR),
            'export/file_codex123.dat' => 'codex image',
        ]);

        Storage::put('imports/codex-export.zip', fopen($zipPath, 'rb'));
        $batch = ImportBatch::query()->create([
            'user_id' => $user->id,
            'status' => 'pending',
            'file_path' => 'imports/codex-export.zip',
            'detected_format' => ImportFormat::ChatGptZip,
        ]);

        app()->call([new ImportConversationJob($batch->id), 'handle']);

        $conversation = Conversation::query()
            ->withoutGlobalScopes()
            ->where('source_platform', SourcePlatform::Codex)
            ->sole();
        Assert::assertSame('Codex task', $conversation->title);
        Assert::assertSame(2, $conversation->messages()->count());
        Assert::assertSame(2, ContentBlock::query()->where('block_type', BlockType::Text)->count());

        $attachment = Attachment::query()->where('source_attachment_id', 'file_codex123')->sole();
        PathAssertions::assertExtension('png', $attachment->filename);
        Assert::assertSame('codex.png', $attachment->filename);
        Assert::assertSame('codex image', Storage::get($attachment->storage_path));
    }

    /**
     * @param  array<string, string>  $entries
     */
    private function createZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'airchive_test_');
        Assert::assertNotFalse($path);

        $zip = new ZipArchive;
        Assert::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($entries as $name => $contents) {
            Assert::assertTrue($zip->addFromString($name, $contents));
        }

        Assert::assertTrue($zip->close());

        return $path;
    }
}
