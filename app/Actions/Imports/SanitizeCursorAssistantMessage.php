<?php

namespace App\Actions\Imports;

use App\Actions\Action;

class SanitizeCursorAssistantMessage extends Action
{
    public function execute(string $text): string
    {
        $text = preg_replace('/^\s*\[REDACTED\]\s*$/mi', '', $text) ?? $text;
        $text = preg_replace('/\n\s*\[REDACTED\]\s*(?=\n|$)/mi', '', $text) ?? $text;
        $text = preg_replace('/\[REDACTED\]/i', '', $text) ?? $text;

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }
}
