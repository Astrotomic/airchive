<?php

namespace Tests\Feature\Actions\Projects;

use App\Actions\Projects\AssignConversationToProjects;
use App\Actions\Projects\ResolveProjectFromIdentifier;
use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\ProjectSourceIdentifier;
use App\Models\User;
use App\ValueObjects\ProjectIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class AssignConversationToProjectsAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_assigns_only_unique_identifiers_and_is_idempotent(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $identifier = new ProjectIdentifier(
            SourcePlatform::Cursor,
            ProjectIdentifierType::CursorWorkspace,
            'workspace-1',
        );

        $projects = AssignConversationToProjects::make()->execute($conversation, [
            $identifier,
            $identifier,
        ]);
        AssignConversationToProjects::make()->execute($conversation, [$identifier]);

        Assert::assertCount(1, $projects);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_source_identifiers', 1);
        $this->assertDatabaseCount('conversation_project', 1);
    }

    public function test_it_returns_each_resolved_project_only_once(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $first = new ProjectIdentifier(
            SourcePlatform::ChatGpt,
            ProjectIdentifierType::ChatGptProject,
            'project-1',
        );
        $second = new ProjectIdentifier(
            SourcePlatform::ChatGpt,
            ProjectIdentifierType::ChatGptGpt,
            'gpt-1',
        );
        $project = ResolveProjectFromIdentifier::make()->execute($user->id, $first);
        ProjectSourceIdentifier::query()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'source_platform' => $second->sourcePlatform,
            'identifier_type' => $second->identifierType,
            'source_identifier' => $second->sourceIdentifier,
            'metadata' => [],
        ]);

        $projects = AssignConversationToProjects::make()->execute($conversation, [$first, $second]);

        Assert::assertCount(1, $projects);
        Assert::assertTrue($projects[0]->is($project));
        $this->assertDatabaseCount('conversation_project', 1);
    }

    public function test_empty_identifiers_do_nothing(): void
    {
        $conversation = $this->conversation(User::factory()->create());

        Assert::assertSame([], AssignConversationToProjects::make()->execute($conversation, []));
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('conversation_project', 0);
    }

    private function conversation(User $user): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => 'cursor',
            'source_conversation_id' => 'conversation-1',
            'title' => 'Conversation',
            'metadata' => [],
        ]);
    }
}
