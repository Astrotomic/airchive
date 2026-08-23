<?php

namespace App\Actions\Projects;

use App\Actions\Action;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

class MergeProjectsIntoProject extends Action
{
    /**
     * @param  iterable<Project>  $sources
     */
    public function execute(iterable $sources, Project $target): Project
    {
        return DB::transaction(function () use ($sources, $target): Project {
            foreach ($sources as $source) {
                if ($source->id === $target->id) {
                    continue;
                }

                $target = MergeProjectIntoProject::make()->execute($source, $target);
            }

            return $target;
        });
    }
}
