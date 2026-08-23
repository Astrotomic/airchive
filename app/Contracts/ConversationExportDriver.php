<?php

namespace App\Contracts;

use App\Models\Conversation;

interface ConversationExportDriver
{
    public function export(Conversation $conversation): string;
}
