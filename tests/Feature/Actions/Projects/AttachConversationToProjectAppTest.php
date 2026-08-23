<?php

namespace Tests\Feature\Actions\Projects;

use App\Actions\Projects\AttachConversationToProject;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class AttachConversationToProjectAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_attaches_a_conversation_idempotently(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user, 'conversation-1');
        $project = $this->project($user, 'Project');

        AttachConversationToProject::make()->execute($conversation, $project);
        AttachConversationToProject::make()->execute($conversation, $project);

        $this->assertDatabaseHas('conversation_project', [
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseCount('conversation_project', 1);
    }

    public function test_it_rejects_models_owned_by_different_users(): void
    {
        $conversation = $this->conversation(User::factory()->create(), 'conversation-1');
        $project = $this->project(User::factory()->create(), 'Project');

        try {
            AttachConversationToProject::make()->execute($conversation, $project);
            Assert::fail('Cross-user models were linked.');
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame(
                'Conversations and projects from different users cannot be linked.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('conversation_project', 0);
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

    private function project(User $user, string $name): Project
    {
        return Project::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'metadata' => [],
        ]);
    }
}
