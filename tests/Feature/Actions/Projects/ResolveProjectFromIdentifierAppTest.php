<?php

namespace Tests\Feature\Actions\Projects;

use App\Actions\Projects\ResolveProjectFromIdentifier;
use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\Models\User;
use App\ValueObjects\ProjectIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class ResolveProjectFromIdentifierAppTest extends AppTestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_project_and_source_identifier(): void
    {
        $user = User::factory()->create();
        $identifier = new ProjectIdentifier(
            SourcePlatform::Cursor,
            ProjectIdentifierType::CursorWorkspace,
            ' workspace-1 ',
            ' Airchive ',
            ['path' => '/projects/airchive'],
        );

        $project = ResolveProjectFromIdentifier::make()->execute($user->id, $identifier);

        Assert::assertSame($user->id, $project->user_id);
        Assert::assertSame(' Airchive ', $project->name);
        Assert::assertSame([], $project->metadata);
        $this->assertDatabaseHas('project_source_identifiers', [
            'user_id' => $user->id,
            'project_id' => $project->id,
            'source_platform' => 'cursor',
            'identifier_type' => 'cursor_workspace',
            'source_identifier' => 'workspace-1',
            'metadata' => json_encode(['path' => '/projects/airchive']),
        ]);
    }

    public function test_it_reuses_an_existing_identifier_without_changing_the_project(): void
    {
        $user = User::factory()->create();
        $identifier = new ProjectIdentifier(
            SourcePlatform::ChatGpt,
            ProjectIdentifierType::ChatGptProject,
            'project-1',
            'Original name',
        );
        $project = ResolveProjectFromIdentifier::make()->execute($user->id, $identifier);

        $resolved = ResolveProjectFromIdentifier::make()->execute($user->id, new ProjectIdentifier(
            SourcePlatform::ChatGpt,
            ProjectIdentifierType::ChatGptProject,
            ' project-1 ',
            'Changed name',
        ));

        Assert::assertTrue($resolved->is($project));
        Assert::assertSame('Original name', $resolved->name);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('project_source_identifiers', 1);
    }

    public function test_resolution_is_scoped_by_user_platform_and_identifier_type(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $action = ResolveProjectFromIdentifier::make();

        $projects = [
            $action->execute($firstUser->id, new ProjectIdentifier(
                SourcePlatform::ChatGpt,
                ProjectIdentifierType::ChatGptProject,
                'shared',
            )),
            $action->execute($secondUser->id, new ProjectIdentifier(
                SourcePlatform::ChatGpt,
                ProjectIdentifierType::ChatGptProject,
                'shared',
            )),
            $action->execute($firstUser->id, new ProjectIdentifier(
                SourcePlatform::Codex,
                ProjectIdentifierType::CodexRepository,
                'shared',
            )),
            $action->execute($firstUser->id, new ProjectIdentifier(
                SourcePlatform::ChatGpt,
                ProjectIdentifierType::ChatGptGpt,
                'shared',
            )),
        ];

        Assert::assertCount(4, array_unique(array_column($projects, 'id')));
        $this->assertDatabaseCount('projects', 4);
    }

    public function test_it_uses_and_limits_the_identifier_as_the_fallback_name(): void
    {
        $user = User::factory()->create();
        $sourceIdentifier = str_repeat('a', 600);

        $project = ResolveProjectFromIdentifier::make()->execute($user->id, new ProjectIdentifier(
            SourcePlatform::Codex,
            ProjectIdentifierType::CodexRepository,
            $sourceIdentifier,
            ' ',
        ));

        Assert::assertSame(str_repeat('a', 255), $project->name);
        Assert::assertSame(
            str_repeat('a', 512),
            $project->sourceIdentifiers()->sole()->source_identifier,
        );
    }

    public function test_it_rejects_an_empty_identifier(): void
    {
        $user = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Project source identifiers cannot be empty.');

        ResolveProjectFromIdentifier::make()->execute($user->id, new ProjectIdentifier(
            SourcePlatform::Cursor,
            ProjectIdentifierType::CursorWorkspace,
            ' ',
        ));
    }
}
