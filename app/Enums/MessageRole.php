<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case System = 'system';
    case Tool = 'tool';
    case Developer = 'developer';
    case Unknown = 'unknown';

    public static function normalize(mixed $value): self
    {
        if (! is_string($value)) {
            return self::Unknown;
        }

        return self::tryFrom(Str::lower(trim($value))) ?? self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Assistant => 'Assistant',
            self::System => 'System',
            self::Tool => 'Tool',
            self::Developer => 'Developer',
            self::Unknown => 'Unknown',
        };
    }

    public function hasDedicatedStyle(): bool
    {
        return match ($this) {
            self::User, self::Assistant, self::System, self::Tool => true,
            default => false,
        };
    }
}
