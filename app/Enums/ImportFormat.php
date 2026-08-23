<?php

namespace App\Enums;

enum ImportFormat: string
{
    case ChatGptJson = 'chatgpt_json';
    case ChatGptZip = 'chatgpt_zip';
    case CursorJsonl = 'cursor_jsonl';
    case CursorExport = 'cursor_export';

    public function label(): string
    {
        return match ($this) {
            self::ChatGptJson => 'ChatGPT JSON',
            self::ChatGptZip => 'ChatGPT ZIP',
            self::CursorJsonl => 'Cursor JSONL',
            self::CursorExport => 'Cursor export',
        };
    }
}
