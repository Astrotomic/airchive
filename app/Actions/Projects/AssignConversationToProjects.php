<?php

namespace App\Actions\Projects;

use App\Actions\Action;
use App\Models\Conversation;
use App\Models\Project;
use App\ValueObjects\ProjectIdentifier;

class AssignConversationToProjects extends Action
{
    /**
     * @param  array<int, ProjectIdentifier>  $identifiers
     * @return array<int, Project>
     */
    public function execute(Conversation $conversation, array $identifiers): array
    {
        $projects = [];

        foreach ($this->uniqueIdentifiers($identifiers) as $identifier) {
            $project = ResolveProjectFromIdentifier::make()->execute($conversation->user_id, $identifier);
            $project->conversations()->syncWithoutDetaching([
                $conversation->id => ['user_id' => $conversation->user_id],
            ]);
            $projects[$project->id] = $project;
        }

        return array_values($projects);
    }

    /**
     * @param  array<int, ProjectIdentifier>  $identifiers
     * @return array<int, ProjectIdentifier>
     */
    private function uniqueIdentifiers(array $identifiers): array
    {
        $unique = [];

        foreach ($identifiers as $identifier) {
            $key = $identifier->sourcePlatform->value."\0"
                .$identifier->identifierType->value."\0"
                .$identifier->sourceIdentifier;
            $unique[$key] = $identifier;
        }

        return array_values($unique);
    }
}
