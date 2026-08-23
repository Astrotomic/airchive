<?php

namespace Tests\Feature\Livewire;

use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_list_orders_by_last_message_with_unknown_dates_last(): void
    {
        $user = User::factory()->create();
        $this->conversation($user, 'Older chat', '2025-01-01 12:00:00');
        $this->conversation($user, 'Unknown chat', null);
        $this->conversation($user, 'Most recent chat', '2026-08-20 18:00:00');

        $this->actingAs($user)
            ->get(route('conversations.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'Most recent chat',
                'Older chat',
                'Unknown chat',
            ]);
    }

    private function conversation(User $user, string $title, ?string $lastMessageAt): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => SourcePlatform::ChatGpt,
            'source_conversation_id' => fake()->uuid(),
            'title' => $title,
            'first_message_at' => $lastMessageAt,
            'last_message_at' => $lastMessageAt,
            'metadata' => [],
        ]);
    }
}
