<?php

namespace App\Actions\Projects;

use App\Actions\Action;
use App\Models\Project;
use App\Models\ProjectSourceIdentifier;
use App\ValueObjects\ProjectIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ResolveProjectFromIdentifier extends Action
{
    public function execute(int $userId, ProjectIdentifier $identifier): Project
    {
        $sourceIdentifier = Str::limit(trim($identifier->sourceIdentifier), 512, '');

        if ($sourceIdentifier === '') {
            throw new InvalidArgumentException('Project source identifiers cannot be empty.');
        }

        $existing = ProjectSourceIdentifier::query()
            ->withoutGlobalScopes()
            ->where('user_id', $userId)
            ->where('source_platform', $identifier->sourcePlatform->value)
            ->where('identifier_type', $identifier->identifierType->value)
            ->where('source_identifier', $sourceIdentifier)
            ->first();

        if ($existing !== null) {
            return Project::query()->withoutGlobalScopes()->findOrFail($existing->project_id);
        }

        return DB::transaction(function () use ($userId, $identifier, $sourceIdentifier): Project {
            $project = Project::query()->withoutGlobalScopes()->create([
                'user_id' => $userId,
                'name' => Str::limit(
                    filled($identifier->suggestedName)
                        ? $identifier->suggestedName
                        : $sourceIdentifier,
                    255,
                    '',
                ),
                'metadata' => [],
            ]);

            ProjectSourceIdentifier::query()->withoutGlobalScopes()->create([
                'user_id' => $userId,
                'project_id' => $project->id,
                'source_platform' => $identifier->sourcePlatform,
                'identifier_type' => $identifier->identifierType,
                'source_identifier' => $sourceIdentifier,
                'metadata' => $identifier->metadata,
            ]);

            return $project;
        });
    }
}
