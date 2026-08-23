<?php

namespace Tests\Integration\Projects;

use App\Actions\Imports\ParseChatGptConversation;
use App\Actions\Imports\WriteCanonicalConversation;
use App\Actions\Projects\MergeProjectIntoProject;
use App\Enums\ImportFormat;
use App\Managers\Imports\Drivers\ChatGptZipImportDriver;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectSourceIdentifier;
use App\Models\User;
use App\ValueObjects\ImportContext;
use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Astrotomic\PhpunitAssertions\Laravel\ModelAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ProjectGroupingAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_chatgpt_project_and_gpt_identifiers_create_multiple_groups_and_are_reused(): void
    {
        $user = User::factory()->create();

        $first = $this->importChatGpt($user, 'chat-1', [
            'conversation_template_id' => 'g-p-project-one',
            'gizmo_id' => 'g-custom-assistant',
        ]);
        $second = $this->importChatGpt($user, 'chat-2', [
            'conversation_template_id' => 'g-p-project-one',
        ]);
        $sharedProject = $first->projects->firstWhere('name', 'ChatGPT Project · project-');
        $secondProject = $second->projects->sole();

        Assert::assertCount(2, $first->projects);
        Assert::assertCount(1, $second->projects);
        Assert::assertNotNull($sharedProject);
        ModelAssertions::assertSame($sharedProject, $secondProject);
        Assert::assertSame($sharedProject->id, $secondProject->id);
        $this->assertDatabaseCount('projects', 2);
        $this->assertDatabaseCount('project_source_identifiers', 2);
        $this->assertDatabaseCount('conversation_project', 3);
        $this->assertDatabaseHas('project_source_identifiers', [
            'source_platform' => 'chatgpt',
            'identifier_type' => 'chatgpt_gpt',
            'source_identifier' => 'g-custom-assistant',
        ]);
    }

    public function test_codex_chat_can_belong_to_multiple_repository_projects(): void
    {
        $user = User::factory()->create();
        $context = $this->context($user, 'codex.json', ImportFormat::ChatGptZip);
        $canonical = app(ChatGptZipImportDriver::class)->parseCodexConversation([
            'id' => 'codex-chat',
            'title' => 'Cross repository work',
            'turns' => [[
                'id' => 'turn-1',
                'role' => 'user',
                'input_items' => [
                    ['type' => 'message', 'content' => [['content_type' => 'text', 'text' => 'Update both repositories']]],
                    ['type' => 'context', 'repo_id' => 'repo-airchive'],
                    ['type' => 'context', 'nested' => ['repo_id' => 'repo-packages']],
                ],
            ]],
        ], $context);

        $conversation = WriteCanonicalConversation::make()->execute($context, $canonical);
        $sourceIdentifiers = ProjectSourceIdentifier::query()->pluck('source_identifier')->all();

        Assert::assertCount(2, $conversation->projects()->get());
        ArrayAssertions::assertIndexed($sourceIdentifiers);
        Assert::assertEqualsCanonicalizing(
            ['repo-airchive', 'repo-packages'],
            $sourceIdentifiers,
        );
    }

    public function test_merge_keeps_all_source_identifiers_for_future_imports(): void
    {
        $user = User::factory()->create();
        $firstConversation = $this->importChatGpt($user, 'chat-a', [
            'conversation_template_id' => 'g-p-alpha',
        ]);
        $secondConversation = $this->importChatGpt($user, 'chat-b', [
            'conversation_template_id' => 'g-p-beta',
        ]);
        $target = $firstConversation->projects()->sole();
        $source = $secondConversation->projects()->sole();

        MergeProjectIntoProject::make()->execute($source, $target);

        $thirdConversation = $this->importChatGpt($user, 'chat-c', [
            'conversation_template_id' => 'g-p-beta',
        ]);
        $target->refresh();

        $this->assertDatabaseCount('projects', 1);
        Assert::assertCount(2, $target->sourceIdentifiers()->get());
        Assert::assertCount(3, $target->conversations()->get());
        ModelAssertions::assertRelated($thirdConversation, 'projects', $target);
        Assert::assertTrue($thirdConversation->projects()->sole()->is($target));
    }

    public function test_project_pages_show_groups_and_can_merge_them(): void
    {
        $user = User::factory()->create();
        $firstConversation = $this->importChatGpt($user, 'chat-list-a', [
            'conversation_template_id' => 'g-p-list-alpha',
        ]);
        $secondConversation = $this->importChatGpt($user, 'chat-list-b', [
            'conversation_template_id' => 'g-p-list-beta',
        ]);
        $target = $firstConversation->projects()->sole();
        $source = $secondConversation->projects()->sole();

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee($target->name)
            ->assertSee($source->name);

        Livewire::actingAs($user)
            ->test('project-show', ['project' => $target])
            ->set('mergeProjectId', $source->id)
            ->call('merge')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('projects', ['id' => $source->id]);
        Assert::assertCount(2, $target->refresh()->conversations()->get());
    }

    public function test_existing_conversations_can_be_backfilled_without_reimporting_archives(): void
    {
        $user = User::factory()->create();
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'chatgpt',
            'source_conversation_id' => 'existing-chat',
            'title' => 'Already imported',
            'metadata' => [
                'conversation_template_id' => 'g-p-existing-project',
                'gizmo_id' => 'g-existing-gpt',
            ],
        ]);

        $exitCode = Artisan::call('archive:projects-backfill', ['--user' => $user->email]);

        Assert::assertSame(0, $exitCode);
        Assert::assertCount(2, $conversation->projects()->get());
        Assert::assertStringContainsString('2 project assignments found', Artisan::output());
    }

    public function test_project_emoji_is_editable_and_does_not_affect_name_sorting(): void
    {
        $user = User::factory()->create();
        $alpha = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Alpha',
            'emoji' => '🚀',
            'metadata' => [],
        ]);
        Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Beta',
            'emoji' => '🍎',
            'metadata' => [],
        ]);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSeeInOrder(['🚀 Alpha', '🍎 Beta']);

        Livewire::actingAs($user)
            ->test('project-show', ['project' => $alpha])
            ->set('emoji', '📚')
            ->set('name', 'Documentation')
            ->call('rename')
            ->assertHasNoErrors()
            ->assertSee('📚 Documentation');

        $this->assertDatabaseHas('projects', [
            'id' => $alpha->id,
            'name' => 'Documentation',
            'emoji' => '📚',
        ]);
    }

    public function test_projects_selected_across_searches_can_be_merged_and_opened(): void
    {
        $user = User::factory()->create();
        $alphaConversation = $this->importChatGpt($user, 'bulk-alpha', [
            'conversation_template_id' => 'g-p-bulk-alpha',
        ]);
        $betaConversation = $this->importChatGpt($user, 'bulk-beta', [
            'conversation_template_id' => 'g-p-bulk-beta',
        ]);
        $gammaConversation = $this->importChatGpt($user, 'bulk-gamma', [
            'conversation_template_id' => 'g-p-bulk-gamma',
        ]);
        $alpha = $alphaConversation->projects()->sole();
        $beta = $betaConversation->projects()->sole();
        $gamma = $gammaConversation->projects()->sole();
        $alpha->update(['name' => 'Alpha']);
        $beta->update(['name' => 'Beta', 'emoji' => '🎯']);
        $gamma->update(['name' => 'Gamma']);

        Livewire::actingAs($user)
            ->test('project-index')
            ->set('search', 'Alpha')
            ->call('selectVisible', [$alpha->id])
            ->set('search', 'Beta')
            ->call('selectVisible', [$beta->id])
            ->set('search', 'Gamma')
            ->call('selectVisible', [$gamma->id])
            ->assertSet('selectedProjectIds', [$alpha->id, $beta->id, $gamma->id])
            ->set('mergeTargetId', $beta->id)
            ->call('mergeSelected')
            ->assertHasNoErrors()
            ->assertRedirect(route('projects.show', $beta));

        $this->assertDatabaseCount('projects', 1);
        Assert::assertSame('🎯 Beta', $beta->refresh()->display_name);
        Assert::assertCount(3, $beta->sourceIdentifiers()->get());
        Assert::assertCount(3, $beta->conversations()->get());
    }

    public function test_mixed_project_conversations_can_be_filtered_by_platform(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Mixed project',
            'metadata' => [],
        ]);
        $chatGpt = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'chatgpt',
            'source_conversation_id' => 'mixed-chatgpt',
            'title' => 'ChatGPT conversation',
            'metadata' => [],
        ]);
        $cursor = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'cursor',
            'source_conversation_id' => 'mixed-cursor',
            'title' => 'Cursor conversation',
            'metadata' => [],
        ]);
        $project->conversations()->attach([
            $chatGpt->id => ['user_id' => $user->id],
            $cursor->id => ['user_id' => $user->id],
        ]);

        Livewire::actingAs($user)
            ->test('project-show', ['project' => $project])
            ->assertSee('All platforms (2)')
            ->assertSee('ChatGPT conversation')
            ->assertSee('Cursor conversation')
            ->set('platform', 'chatgpt')
            ->assertSee('1 of 2 conversations')
            ->assertSee('ChatGPT conversation')
            ->assertDontSee('Cursor conversation');
    }

    public function test_project_cards_summarize_source_identifier_counts_by_platform(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Platform summary',
            'metadata' => [],
        ]);

        foreach ([
            ['chatgpt', 'chatgpt_project', 'g-p-one'],
            ['chatgpt', 'chatgpt_gpt', 'g-one'],
            ['cursor', 'cursor_workspace', 'workspace-one'],
            ['cursor', 'cursor_workspace', 'workspace-two'],
            ['cursor', 'cursor_workspace', 'workspace-three'],
        ] as [$platform, $type, $identifier]) {
            ProjectSourceIdentifier::query()->create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'source_platform' => $platform,
                'identifier_type' => $type,
                'source_identifier' => $identifier,
                'metadata' => [],
            ]);
        }

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('2 ChatGPT')
            ->assertSee('|', false)
            ->assertSee('3 Cursor')
            ->assertDontSee('5 source identifiers')
            ->assertDontSee('cursor_workspace');
    }

    public function test_conversation_without_a_project_can_be_manually_assigned_from_sidebar(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Manual project',
            'emoji' => '📌',
            'metadata' => [],
        ]);
        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'chatgpt',
            'source_conversation_id' => 'manual-project-chat',
            'title' => 'Unassigned conversation',
            'metadata' => [],
        ]);

        Livewire::actingAs($user)
            ->test('conversation-show', ['conversation' => $conversation])
            ->assertSee('No project assigned.')
            ->assertSee('📌 Manual project')
            ->set('projectToAssign', $project->id)
            ->call('assignProject')
            ->assertHasNoErrors()
            ->assertSee('📌 Manual project')
            ->assertDontSee('No project assigned.');

        $this->assertDatabaseHas('conversation_project', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_conversations_can_be_searched_inside_a_project(): void
    {
        $user = User::factory()->create();
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Searchable project',
            'metadata' => [],
        ]);
        $matching = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'chatgpt',
            'source_conversation_id' => 'project-search-match',
            'title' => 'Needle conversation',
            'metadata' => [],
        ]);
        $other = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'cursor',
            'source_conversation_id' => 'project-search-other',
            'title' => 'Unrelated conversation',
            'metadata' => [],
        ]);
        $project->conversations()->attach([
            $matching->id => ['user_id' => $user->id],
            $other->id => ['user_id' => $user->id],
        ]);

        Livewire::actingAs($user)
            ->test('project-show', ['project' => $project])
            ->assertSee('Needle conversation')
            ->assertSee('Unrelated conversation')
            ->set('search', 'Needle')
            ->assertSee('1 of 2 conversations')
            ->assertSee('Needle conversation')
            ->assertDontSee('Unrelated conversation');
    }

    /** @param array<string, mixed> $metadata */
    private function importChatGpt(User $user, string $conversationId, array $metadata): Conversation
    {
        $data = [
            'id' => $conversationId,
            'title' => 'Conversation '.$conversationId,
            'current_node' => 'message-'.$conversationId,
            'mapping' => [
                'message-'.$conversationId => [
                    'id' => 'message-'.$conversationId,
                    'parent' => null,
                    'children' => [],
                    'message' => [
                        'id' => 'message-'.$conversationId,
                        'author' => ['role' => 'user'],
                        'create_time' => 1_700_000_000,
                        'content' => ['content_type' => 'text', 'parts' => ['Hello']],
                        'metadata' => [],
                    ],
                ],
            ],
            ...$metadata,
        ];
        $contents = json_encode($data, JSON_THROW_ON_ERROR);
        $context = $this->context($user, $conversationId.'.json', ImportFormat::ChatGptJson, $contents);
        [$canonical] = ParseChatGptConversation::make()->execute($context, $contents);

        return WriteCanonicalConversation::make()
            ->execute($context, $canonical)
            ->load('projects');
    }

    private function context(User $user, string $file, ImportFormat $format, string $contents = ''): ImportContext
    {
        return new ImportContext(
            userId: $user->id,
            filePath: $file,
            sourceFormat: $format,
            rawChecksum: hash('sha256', $contents !== '' ? $contents : $file),
            sourceFile: $file,
        );
    }
}
