<?php

namespace Tests\Feature\Actions\Projects;

use App\Actions\Projects\MergeProjectsIntoProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class MergeProjectsIntoProjectAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_merges_multiple_projects_and_skips_the_target(): void
    {
        $user = User::factory()->create();
        $target = $this->project($user, 'Target');
        $first = $this->project($user, 'First');
        $second = $this->project($user, 'Second');

        $result = MergeProjectsIntoProject::make()->execute(
            (static function () use ($first, $target, $second): iterable {
                yield $first;
                yield $target;
                yield $second;
            })(),
            $target,
        );

        Assert::assertTrue($result->is($target));
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', ['id' => $target->id]);
    }

    public function test_empty_sources_return_the_target(): void
    {
        $target = $this->project(User::factory()->create(), 'Target');

        $result = MergeProjectsIntoProject::make()->execute([], $target);

        Assert::assertSame($target, $result);
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
