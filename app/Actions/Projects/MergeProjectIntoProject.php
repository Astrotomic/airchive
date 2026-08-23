<?php

namespace App\Actions\Projects;

use App\Actions\Action;
use App\Models\Project;
use App\Models\ProjectSourceIdentifier;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MergeProjectIntoProject extends Action
{
    public function execute(Project $source, Project $target): Project
    {
        if ($source->id === $target->id) {
            return $target;
        }

        if ($source->user_id !== $target->user_id) {
            throw new InvalidArgumentException('Projects from different users cannot be merged.');
        }

        return DB::transaction(function () use ($source, $target): Project {
            $targetConversationIds = DB::table('conversation_project')
                ->where('project_id', $target->id)
                ->pluck('conversation_id');

            if ($targetConversationIds->isNotEmpty()) {
                DB::table('conversation_project')
                    ->where('project_id', $source->id)
                    ->whereIn('conversation_id', $targetConversationIds)
                    ->delete();
            }

            DB::table('conversation_project')
                ->where('project_id', $source->id)
                ->update(['project_id' => $target->id, 'updated_at' => now()]);

            ProjectSourceIdentifier::query()
                ->withoutGlobalScopes()
                ->where('user_id', $target->user_id)
                ->where('project_id', $source->id)
                ->update(['project_id' => $target->id]);

            $source->delete();

            return $target->refresh()->load('sourceIdentifiers');
        });
    }
}
