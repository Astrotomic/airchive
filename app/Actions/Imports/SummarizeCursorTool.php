<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use Illuminate\Support\Str;

class SummarizeCursorTool extends Action
{
    /**
     * @param  array<string, mixed>|null  $input
     */
    public function execute(string $name, ?array $input = null): string
    {
        if ($input === null || $input === []) {
            return $name;
        }

        return match ($name) {
            'Read' => 'Read '.$this->stringValue($input['path'] ?? $input['target_file'] ?? 'file'),
            'Glob' => 'Glob '.$this->stringValue($input['glob_pattern'] ?? '**/*'),
            'Grep' => 'Grep '.$this->stringValue($input['pattern'] ?? ''),
            'Shell' => 'Shell '.$this->stringValue($input['description'] ?? $this->truncate($this->stringValue($input['command'] ?? 'command'), 80)),
            'WebSearch' => 'Search '.$this->stringValue($input['search_term'] ?? ''),
            'WebFetch' => 'Fetch '.$this->stringValue($input['url'] ?? ''),
            'Task', 'CreatePlan', 'AskQuestion', 'SwitchMode' => $name,
            default => $name.' '.$this->truncate(json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 80),
        };
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private function truncate(string $value, int $length): string
    {
        return Str::limit(trim($value), $length, '…');
    }
}
