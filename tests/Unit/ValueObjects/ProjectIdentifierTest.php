<?php

namespace Tests\Unit\ValueObjects;

use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\ValueObjects\ProjectIdentifier;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class ProjectIdentifierTest extends UnitTestCase
{
    public function test_it_stores_project_identifier_data(): void
    {
        $identifier = new ProjectIdentifier(
            sourcePlatform: SourcePlatform::Cursor,
            identifierType: ProjectIdentifierType::CursorWorkspace,
            sourceIdentifier: 'workspace-1',
            suggestedName: 'Airchive',
            metadata: ['path' => '/projects/airchive'],
        );

        Assert::assertSame(SourcePlatform::Cursor, $identifier->sourcePlatform);
        Assert::assertSame(ProjectIdentifierType::CursorWorkspace, $identifier->identifierType);
        Assert::assertSame('workspace-1', $identifier->sourceIdentifier);
        Assert::assertSame('Airchive', $identifier->suggestedName);
        Assert::assertSame(['path' => '/projects/airchive'], $identifier->metadata);
    }

    public function test_optional_project_identifier_data_has_empty_defaults(): void
    {
        $identifier = new ProjectIdentifier(
            SourcePlatform::Codex,
            ProjectIdentifierType::CodexRepository,
            'repository-1',
        );

        Assert::assertNull($identifier->suggestedName);
        Assert::assertSame([], $identifier->metadata);
    }
}
