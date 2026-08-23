<?php

namespace Tests\Integration\Exports;

use App\Enums\BlockType;
use App\Enums\SourcePlatform;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;
use ZipArchive;

class WebExportAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_export_page_has_search_filters_and_export_options(): void
    {
        $user = User::factory()->create();
        Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Web project',
            'emoji' => '📦',
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->get(route('exports.index'))
            ->assertOk()
            ->assertSee('No project')
            ->assertSee('All platforms')
            ->assertSee('📦 Web project')
            ->assertSee('Chats and attached files')
            ->assertSee('Download ZIP');
    }

    public function test_filtered_web_export_downloads_zip_and_deletes_temporary_files(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Launch project',
            'metadata' => [],
        ]);
        $included = $this->conversation(
            $user,
            SourcePlatform::ChatGpt,
            'Assigned launch',
            'Rocket launch checklist',
        );
        $excluded = $this->conversation(
            $user,
            SourcePlatform::Cursor,
            'Unassigned launch',
            'Rocket launch checklist',
        );
        $project->conversations()->attach($included->id, ['user_id' => $user->id]);

        $response = $this->actingAs($user)->post(route('exports.download'), [
            'query' => 'rocket',
            'platform' => SourcePlatform::ChatGpt->value,
            'project' => (string) $project->id,
            'mode' => 'chats_only',
            'format' => 'json',
        ]);

        $response->assertOk()->assertDownload();
        $zipPath = $response->baseResponse->getFile()->getPathname();
        $workingDirectory = substr($zipPath, 0, -4);
        Assert::assertFileExists($zipPath);
        Assert::assertDirectoryDoesNotExist($workingDirectory);

        $zip = new ZipArchive;
        Assert::assertTrue($zip->open($zipPath));
        $entries = collect(range(0, $zip->numFiles - 1))
            ->map(fn (int $index): string => $zip->getNameIndex($index));
        $entryList = $entries->all();
        ArrayAssertions::assertIndexed($entryList);
        ArrayAssertions::assertContainsAll([
            "chats/{$included->id}-assigned-launch.json",
        ], $entryList);
        Assert::assertContains(
            "chats/{$included->id}-assigned-launch.json",
            $entryList,
            'ZIP entries: '.$entries->implode(', '),
        );
        Assert::assertNotContains("chats/{$excluded->id}-unassigned-launch.json", $entryList);
        $zip->close();

        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        Assert::assertFileDoesNotExist($zipPath);
    }

    public function test_web_export_rejects_an_empty_result_set(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('exports.index'))
            ->post(route('exports.download'), [
                'query' => 'nothing-matches-this',
                'platform' => '',
                'project' => '',
                'mode' => 'chats_and_files',
                'format' => 'md',
            ])
            ->assertRedirect(route('exports.index'))
            ->assertSessionHasErrors('query');
    }

    private function conversation(
        User $user,
        SourcePlatform $platform,
        string $title,
        string $body,
    ): Conversation {
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => $platform,
            'source_conversation_id' => fake()->uuid(),
            'title' => $title,
            'metadata' => [],
        ]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => fake()->uuid(),
            'role' => 'assistant',
            'created_at' => now(),
            'is_on_canonical_path' => true,
            'is_hidden' => false,
            'metadata' => [],
        ]);
        ContentBlock::query()->create([
            'message_id' => $message->id,
            'position' => 0,
            'block_type' => BlockType::Text,
            'text_content' => $body,
        ]);

        return $conversation;
    }
}
