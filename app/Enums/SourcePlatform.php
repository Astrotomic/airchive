<?php

namespace App\Enums;

enum SourcePlatform: string
{
    case ChatGpt = 'chatgpt';
    case Codex = 'codex';
    case Cursor = 'cursor';

    public function label(): string
    {
        return match ($this) {
            self::ChatGpt => 'ChatGPT',
            self::Codex => 'Codex',
            self::Cursor => 'Cursor',
        };
    }
}
