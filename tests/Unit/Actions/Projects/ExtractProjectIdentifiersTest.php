<?php

namespace Tests\Unit\Actions\Projects;

use App\Actions\Projects\ExtractProjectIdentifiers;
use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class ExtractProjectIdentifiersTest extends UnitTestCase
{
    public function test_extracts_every_chatgpt_identifier_type(): void
    {
        $identifiers = ExtractProjectIdentifiers::make()->execute(SourcePlatform::ChatGpt, [
            'conversation_template_id' => ' template-123456789 ',
            'gizmo_id' => 'g-gizmo-12345678901234567890',
            'project_id' => 'project-without-prefix',
            'workspace_id' => 'workspace-123456',
        ]);

        Assert::assertSame([
            ProjectIdentifierType::ChatGptTemplate,
            ProjectIdentifierType::ChatGptGpt,
            ProjectIdentifierType::ChatGptProject,
            ProjectIdentifierType::ChatGptWorkspace,
        ], array_column($identifiers, 'identifierType'));
        Assert::assertSame([
            'ChatGPT Group · template-123',
            'GPT · g-gizmo-1234567890',
            'ChatGPT Project · ect-with',
            'ChatGPT Workspace · workspace-12',
        ], array_column($identifiers, 'suggestedName'));
        Assert::assertSame([
            ['source_field' => 'conversation_template_id'],
            ['source_field' => 'gizmo_id'],
            ['source_field' => 'project_id'],
            ['source_field' => 'workspace_id'],
        ], array_column($identifiers, 'metadata'));
    }

    public function test_chatgpt_value_prefix_can_override_the_source_field_type(): void
    {
        $identifiers = ExtractProjectIdentifiers::make()->execute(SourcePlatform::ChatGpt, [
            'conversation_template_id' => 'g-p-project-1',
            'workspace_id' => 'g-gizmo-1',
        ]);

        Assert::assertSame(ProjectIdentifierType::ChatGptProject, $identifiers[0]->identifierType);
        Assert::assertSame(ProjectIdentifierType::ChatGptGpt, $identifiers[1]->identifierType);
    }

    public function test_chatgpt_ignores_missing_non_string_and_blank_identifiers(): void
    {
        $identifiers = ExtractProjectIdentifiers::make()->execute(SourcePlatform::ChatGpt, [
            'conversation_template_id' => null,
            'gizmo_id' => 123,
            'project_id' => ' ',
        ]);

        Assert::assertSame([], $identifiers);
    }

    public function test_extracts_cursor_workspace(): void
    {
        $identifier = ExtractProjectIdentifiers::make()
            ->execute(SourcePlatform::Cursor, ' workspace-1 ')[0];

        Assert::assertSame(SourcePlatform::Cursor, $identifier->sourcePlatform);
        Assert::assertSame(ProjectIdentifierType::CursorWorkspace, $identifier->identifierType);
        Assert::assertSame('workspace-1', $identifier->sourceIdentifier);
        Assert::assertSame('Cursor · workspace-1', $identifier->suggestedName);
        Assert::assertSame(['export_workspace' => 'workspace-1'], $identifier->metadata);
    }

    public function test_names_cursor_empty_window_and_rejects_invalid_workspaces(): void
    {
        $identifier = ExtractProjectIdentifiers::make()
            ->execute(SourcePlatform::Cursor, 'empty-window')[0];

        Assert::assertSame('Cursor · Empty Window', $identifier->suggestedName);
        Assert::assertSame([], ExtractProjectIdentifiers::make()->execute(SourcePlatform::Cursor, null));
        Assert::assertSame([], ExtractProjectIdentifiers::make()->execute(SourcePlatform::Cursor, 123));
        Assert::assertSame([], ExtractProjectIdentifiers::make()->execute(SourcePlatform::Cursor, ' '));
    }

    public function test_extracts_unique_nested_codex_repository_ids(): void
    {
        $identifiers = ExtractProjectIdentifiers::make()->execute(SourcePlatform::Codex, [
            'turns' => [
                ['repo_id' => ' repo-1 '],
                ['nested' => ['repo_id' => 42]],
                ['repo_id' => 'repo-1'],
                ['repo_id' => ' '],
                ['repo_id' => null],
            ],
            'repo_id' => ['invalid'],
        ]);

        Assert::assertSame(['repo-1', '42'], array_column($identifiers, 'sourceIdentifier'));
        Assert::assertSame(
            ['Repository · repo-1', 'Repository · 42'],
            array_column($identifiers, 'suggestedName'),
        );
        Assert::assertSame([], ExtractProjectIdentifiers::make()->execute(SourcePlatform::Codex, 'repo-1'));
    }
}
