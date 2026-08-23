<?php

namespace Tests\Feature\Actions\Projects;

use App\Actions\Projects\MergeProjectIntoProject;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectSourceIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class MergeProjectIntoProjectAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_moves_unique_relations_and_removes_duplicate_conversation_links(): void
    {
        $user = User::factory()->create();
        $source = $this->project($user, 'Source');
        $target = $this->project($user, 'Target');
        $shared = $this->conversation($user, 'shared');
        $sourceOnly = $this->conversation($user, 'source-only');
        $targetOnly = $this->conversation($user, 'target-only');
        $source->conversations()->attach([
            $shared->id => ['user_id' => $user->id],
            $sourceOnly->id => ['user_id' => $user->id],
        ]);
        $target->conversations()->attach([
            $shared->id => ['user_id' => $user->id],
            $targetOnly->id => ['user_id' => $user->id],
        ]);
        $identifier = $this->identifier($source, 'source-id');

        $result = MergeProjectIntoProject::make()->execute($source, $target);

        Assert::assertTrue($result->is($target));
        Assert::assertEqualsCanonicalizing(
            [$shared->id, $sourceOnly->id, $targetOnly->id],
            $result->conversations()->pluck('conversations.id')->all(),
        );
        Assert::assertSame($target->id, $identifier->fresh()->project_id);
        Assert::assertTrue($result->relationLoaded('sourceIdentifiers'));
        $this->assertDatabaseMissing('projects', ['id' => $source->id]);
        $this->assertDatabaseCount('conversation_project', 3);
    }

    public function test_it_moves_relations_when_target_has_no_conversations(): void
    {
        $user = User::factory()->create();
        $source = $this->project($user, 'Source');
        $target = $this->project($user, 'Target');
        $conversation = $this->conversation($user, 'source-only');
        $source->conversations()->attach($conversation, ['user_id' => $user->id]);

        MergeProjectIntoProject::make()->execute($source, $target);

        $this->assertDatabaseHas('conversation_project', [
            'conversation_id' => $conversation->id,
            'project_id' => $target->id,
        ]);
    }

    public function test_merging_a_project_into_itself_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Project');

        $result = MergeProjectIntoProject::make()->execute($project, $project);

        Assert::assertSame($project, $result);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_it_rejects_projects_owned_by_different_users(): void
    {
        $source = $this->project(User::factory()->create(), 'Source');
        $target = $this->project(User::factory()->create(), 'Target');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Projects from different users cannot be merged.');

        MergeProjectIntoProject::make()->execute($source, $target);
    }

    private function project(User $user, string $name): Project
    {
        return Project::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'metadata' => [],
        ]);
    }

    private function conversation(User $user, string $sourceId): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'chatgpt',
            'source_conversation_id' => $sourceId,
            'title' => $sourceId,
            'metadata' => [],
        ]);
    }

    private function identifier(Project $project, string $sourceId): ProjectSourceIdentifier
    {
        return ProjectSourceIdentifier::query()->create([
            'user_id' => $project->user_id,
            'project_id' => $project->id,
            'source_platform' => 'chatgpt',
            'identifier_type' => 'chatgpt_project',
            'source_identifier' => $sourceId,
            'metadata' => [],
        ]);
    }
}
