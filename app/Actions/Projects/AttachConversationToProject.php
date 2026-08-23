<?php

namespace App\Actions\Projects;

use App\Actions\Action;
use App\Models\Conversation;
use App\Models\Project;
use InvalidArgumentException;

class AttachConversationToProject extends Action
{
    public function execute(Conversation $conversation, Project $project): void
    {
        if ($conversation->user_id !== $project->user_id) {
            throw new InvalidArgumentException('Conversations and projects from different users cannot be linked.');
        }

        $project->conversations()->syncWithoutDetaching([
            $conversation->id => ['user_id' => $conversation->user_id],
        ]);
    }
}
