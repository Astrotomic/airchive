<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $auth, Conversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }

    public function update(User $auth, Conversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }

    public function delete(User $auth, Conversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }

    public function export(User $auth, Conversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }
}
