<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $auth, Project $project): bool
    {
        return $project->user_id === $auth->id;
    }

    public function update(User $auth, Project $project): bool
    {
        return $project->user_id === $auth->id;
    }

    public function delete(User $auth, Project $project): bool
    {
        return $project->user_id === $auth->id;
    }
}
