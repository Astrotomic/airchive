<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum ExportFormat: string
{
    case Markdown = 'md';
    case Html = 'html';
    case Json = 'json';

    public static function parse(string $value): ?self
    {
        $value = Str::lower(trim($value));

        return self::tryFrom($value === 'markdown' ? self::Markdown->value : $value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Markdown => 'Markdown',
            self::Html => 'HTML',
            self::Json => 'JSON',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Markdown => 'text/markdown; charset=UTF-8',
            self::Html => 'text/html; charset=UTF-8',
            self::Json => 'application/json; charset=UTF-8',
        };
    }
}
