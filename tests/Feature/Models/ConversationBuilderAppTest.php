<?php

namespace Tests\Feature\Models;

use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ConversationBuilderAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_platform_filter_accepts_blank_and_known_values_and_rejects_unknown_values(): void
    {
        $user = User::factory()->create();
        $chatGpt = $this->conversation($user, 'chatgpt', SourcePlatform::ChatGpt);
        $cursor = $this->conversation($user, 'cursor', SourcePlatform::Cursor);

        Assert::assertEqualsCanonicalizing(
            [$chatGpt->id, $cursor->id],
            Conversation::query()->forPlatform(null)->pluck('id')->all(),
        );
        Assert::assertSame(
            [$chatGpt->id],
            Conversation::query()->forPlatform(SourcePlatform::ChatGpt->value)->pluck('id')->all(),
        );
        Assert::assertSame([], Conversation::query()->forPlatform('unknown')->pluck('id')->all());
    }

    public function test_project_filter_supports_assigned_unassigned_blank_and_invalid_values(): void
    {
        $user = User::factory()->create();
        $assigned = $this->conversation($user, 'assigned', SourcePlatform::ChatGpt);
        $unassigned = $this->conversation($user, 'unassigned', SourcePlatform::Cursor);
        $project = Project::query()->create([
            'user_id' => $user->id,
            'name' => 'Project',
            'metadata' => [],
        ]);
        $project->conversations()->attach($assigned, ['user_id' => $user->id]);

        Assert::assertEqualsCanonicalizing(
            [$assigned->id, $unassigned->id],
            Conversation::query()->forProject('')->pluck('id')->all(),
        );
        Assert::assertSame([$assigned->id], Conversation::query()->forProject((string) $project->id)->pluck('id')->all());
        Assert::assertSame([$unassigned->id], Conversation::query()->forProject('none')->pluck('id')->all());
        Assert::assertSame([], Conversation::query()->forProject('invalid')->pluck('id')->all());
    }

    public function test_latest_by_message_orders_dates_descending_nulls_last_and_breaks_ties_by_id(): void
    {
        $user = User::factory()->create();
        $old = $this->conversation($user, 'old', SourcePlatform::ChatGpt, '2026-08-20 10:00:00');
        $tieFirst = $this->conversation($user, 'tie-first', SourcePlatform::ChatGpt, '2026-08-23 10:00:00');
        $tieSecond = $this->conversation($user, 'tie-second', SourcePlatform::Cursor, '2026-08-23 10:00:00');
        $unknown = $this->conversation($user, 'unknown', SourcePlatform::Cursor);

        Assert::assertSame([
            $tieSecond->id,
            $tieFirst->id,
            $old->id,
            $unknown->id,
        ], Conversation::query()->latestByMessage()->pluck('id')->all());
    }

    public function test_blank_search_keeps_the_query_unfiltered(): void
    {
        $user = User::factory()->create();
        $first = $this->conversation($user, 'first', SourcePlatform::ChatGpt);
        $second = $this->conversation($user, 'second', SourcePlatform::Cursor);

        Assert::assertEqualsCanonicalizing(
            [$first->id, $second->id],
            Conversation::query()->search('  ')->pluck('id')->all(),
        );
    }

    private function conversation(
        User $user,
        string $sourceId,
        SourcePlatform $platform,
        ?string $lastMessageAt = null,
    ): Conversation {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'source_platform' => $platform,
            'source_conversation_id' => $sourceId,
            'title' => $sourceId,
            'last_message_at' => $lastMessageAt === null ? null : CarbonImmutable::parse($lastMessageAt),
            'metadata' => [],
        ]);
    }
}
