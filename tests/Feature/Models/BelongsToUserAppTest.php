<?php

namespace Tests\Feature\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class BelongsToUserAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_authenticated_models_are_automatically_owned_and_queries_are_tenant_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $otherProject = Project::query()->withoutGlobalScopes()->create([
            'user_id' => $other->id,
            'name' => 'Other project',
            'metadata' => [],
        ]);
        $this->actingAs($user);

        $owned = Project::query()->create([
            'name' => 'Owned project',
            'metadata' => [],
        ]);

        Assert::assertSame($user->id, $owned->user_id);
        Assert::assertTrue($owned->user->is($user));
        Assert::assertSame([$owned->id], Project::query()->pluck('id')->all());
        Assert::assertTrue(Project::query()->withoutGlobalScopes()->findOrFail($otherProject->id)->is($otherProject));
    }

    public function test_guest_queries_are_not_tenant_scoped(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        Project::query()->withoutGlobalScopes()->create([
            'user_id' => $first->id,
            'name' => 'First',
            'metadata' => [],
        ]);
        Project::query()->withoutGlobalScopes()->create([
            'user_id' => $second->id,
            'name' => 'Second',
            'metadata' => [],
        ]);
        Auth::logout();

        Assert::assertSame(2, Project::query()->count());
    }
}
