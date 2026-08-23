<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use Illuminate\Support\Str;

class BuildCursorConversationTitle extends Action
{
    public function execute(string $text): string
    {
        $clean = SanitizeCursorUserMessage::make()->execute($text);
        $firstLine = Str::of($clean)->before("\n")->trim()->toString();

        if ($firstLine !== '') {
            return Str::limit($firstLine, 80);
        }

        return Str::limit($clean, 80);
    }
}
