<?php

namespace App\Actions\Imports;

use App\Actions\Action;

class BuildCursorToolSearchText extends Action
{
    public function execute(string $name, mixed $input): string
    {
        $summary = SummarizeCursorTool::make()->execute($name, is_array($input) ? $input : null);

        if (! is_array($input)) {
            return $summary;
        }

        return $summary.' '.(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
