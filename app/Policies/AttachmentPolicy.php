<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    public function view(User $auth, Attachment $attachment): bool
    {
        return $attachment->user_id === $auth->id;
    }
}
