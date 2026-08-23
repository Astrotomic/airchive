<?php

namespace App\ValueObjects;

use Illuminate\Support\Str;
use Stringable;

final readonly class ModelDisplayName implements Stringable
{
    public function __construct(private string $slug) {}

    public function __toString(): string
    {
        $slug = Str::of($this->slug)->trim()->lower()->toString();

        $label = match ($slug) {
            'agent-mode' => 'Agent mode',
            'auto' => 'Auto',
            'research' => 'Research',
            'text-davinci-002-render',
            'text-davinci-002-render-sha' => 'Text Davinci 002',
            default => null,
        };

        if ($label !== null) {
            return $label;
        }

        if (preg_match('/^gpt-(\d+o)(?:-(mini))?$/i', $slug, $matches) === 1) {
            return 'GPT'.$matches[1].(isset($matches[2]) ? ' mini' : '');
        }

        if (preg_match('/^gpt-(\d+)(?:[.-](\d+))?(?:-(.+))?$/i', $slug, $matches) !== 1) {
            return $slug;
        }

        $name = 'GPT'.$matches[1].(isset($matches[2]) && $matches[2] !== '' ? '.'.$matches[2] : '');
        $variant = strtolower($matches[3] ?? '');
        $suffix = match ($variant) {
            '' => '',
            'thinking', 'auto-thinking' => 't',
            'instant' => 'i',
            't-mini' => 't mini',
            'sol-wm' => ' Sol',
            default => null,
        };

        return $suffix === null ? $slug : $name.$suffix;
    }
}
