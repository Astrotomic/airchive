<?php

namespace Tests\Integration\Search;

use App\Enums\BlockType;
use App\Enums\SourcePlatform;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class SearchAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_finds_conversation_by_assistant_content(): void
    {
        [$user, $conversation] = $this->seedConversation('WordPress help', 'You can run WordPress with Docker.');

        $ids = Conversation::query()->whereBelongsTo($user)->search('wordpress')->pluck('id');

        Assert::assertTrue($ids->contains($conversation->id));
    }

    public function test_finds_conversation_by_title(): void
    {
        [$user, $conversation] = $this->seedConversation('WordPress help', 'Unrelated body');

        $ids = Conversation::query()->whereBelongsTo($user)->search('wordpress')->pluck('id');

        Assert::assertTrue($ids->contains($conversation->id));
    }

    public function test_does_not_match_unrelated_terms(): void
    {
        [$user, $conversation] = $this->seedConversation('Images', 'Here is a picture of a dashboard.');

        $ids = Conversation::query()->whereBelongsTo($user)->search('puncture')->pluck('id');

        Assert::assertFalse($ids->contains($conversation->id));
    }

    public function test_user_isolation(): void
    {
        [$userA, $conversationA] = $this->seedConversation('WordPress help', 'wordpress content');
        $userB = User::factory()->create();

        $ids = Conversation::query()->whereBelongsTo($userB)->search('wordpress')->pluck('id');

        Assert::assertFalse($ids->contains($conversationA->id));
    }

    public function test_search_view_lists_conversations_without_rendering_message_bodies(): void
    {
        [$user] = $this->seedConversation(
            'WordPress infrastructure',
            'A long WordPress message body that should never be rendered in the conversation result list.',
        );

        Livewire::actingAs($user)
            ->test('conversation-search')
            ->set('query', 'WordPress')
            ->assertSee('1 matching conversation')
            ->assertSee('WordPress infrastructure')
            ->assertDontSee('A long WordPress message body');
    }

    public function test_global_search_filters_by_platform_project_and_no_project(): void
    {
        [$user, $assigned] = $this->seedConversation('Assigned ChatGPT chat', 'Assigned body');
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Search project',
            'metadata' => [],
        ]);
        $project->conversations()->attach($assigned->id, ['user_id' => $user->id]);
        Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::Cursor,
            'source_conversation_id' => 'unassigned-cursor-search',
            'title' => 'Unassigned Cursor chat',
            'metadata' => [],
        ]);

        Livewire::actingAs($user)
            ->test('conversation-search')
            ->set('platform', SourcePlatform::Cursor->value)
            ->assertSee('Unassigned Cursor chat')
            ->assertDontSee('Assigned ChatGPT chat')
            ->set('platform', '')
            ->set('projectFilter', 'none')
            ->assertSee('Unassigned Cursor chat')
            ->assertDontSee('Assigned ChatGPT chat')
            ->set('projectFilter', (string) $project->id)
            ->assertSee('Assigned ChatGPT chat')
            ->assertDontSee('Unassigned Cursor chat');
    }

    /**
     * @return array{0: User, 1: Conversation}
     */
    private function seedConversation(string $title, string $body): array
    {
        $user = User::factory()->create();

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => fake()->uuid(),
            'title' => $title,
            'metadata' => [],
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'source_message_id' => 'assistant1',
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

        return [$user, $conversation];
    }
}
