<?php

namespace App\Models\Builders;

use App\Models\Session;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<Session> */
final class SessionBuilder extends Builder
{
    public function revoke(string $sessionId): bool
    {
        return $this->whereKey($sessionId)->delete() > 0;
    }

    public function revokeOthers(string $currentSessionId): int
    {
        return (int) $this->whereKeyNot($currentSessionId)->delete();
    }
}
