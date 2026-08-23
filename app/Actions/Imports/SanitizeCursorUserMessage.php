<?php

namespace App\Actions\Imports;

use App\Actions\Action;

class SanitizeCursorUserMessage extends Action
{
    public function execute(string $text): string
    {
        $text = preg_replace('/<timestamp>.*?<\/timestamp>\s*/is', '', $text) ?? $text;

        if (preg_match('/<user_query>\s*(.*?)\s*<\/user_query>/is', $text, $matches) === 1) {
            $text = $matches[1];
        }

        $text = preg_replace('/<\/?user_query>/i', '', $text) ?? $text;

        return trim($text);
    }
}
