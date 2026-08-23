<?php

namespace App\Actions\Imports;

use App\Actions\Action;

class ExtractCursorTimestamp extends Action
{
    public function execute(string $text): ?string
    {
        if (preg_match('/<timestamp>\s*(.*?)\s*<\/timestamp>/is', $text, $matches) !== 1) {
            return null;
        }

        $timestamp = trim($matches[1]);

        return $timestamp !== '' ? $timestamp : null;
    }
}
