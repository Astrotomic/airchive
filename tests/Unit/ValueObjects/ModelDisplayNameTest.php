<?php

namespace Tests\Unit\ValueObjects;

use App\ValueObjects\ModelDisplayName;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelDisplayNameTest extends TestCase
{
    #[DataProvider('modelNames')]
    public function test_it_formats_model_slugs_for_display(string $slug, string $expected): void
    {
        Assert::assertSame($expected, (string) new ModelDisplayName($slug));
    }

    /** @return array<string, array{string, string}> */
    public static function modelNames(): array
    {
        return [
            'GPT-4o' => ['gpt-4o', 'GPT4o'],
            'GPT-4o mini' => ['gpt-4o-mini', 'GPT4o mini'],
            'GPT-5' => ['gpt-5', 'GPT5'],
            'GPT-5 thinking' => ['gpt-5-thinking', 'GPT5t'],
            'GPT-5 thinking mini' => ['gpt-5-t-mini', 'GPT5t mini'],
            'GPT-5.1' => ['gpt-5-1', 'GPT5.1'],
            'GPT-5.2 thinking' => ['gpt-5-2-thinking', 'GPT5.2t'],
            'GPT-5.2 automatic thinking' => ['gpt-5-2-auto-thinking', 'GPT5.2t'],
            'GPT-5.2 instant' => ['gpt-5-2-instant', 'GPT5.2i'],
            'GPT-5.6 Sol' => ['gpt-5.6-sol-wm', 'GPT5.6 Sol'],
            'agent mode' => ['agent-mode', 'Agent mode'],
            'auto' => ['auto', 'Auto'],
            'research' => ['research', 'Research'],
            'legacy text Davinci' => ['text-davinci-002-render-sha', 'Text Davinci 002'],
            'unknown slug' => ['gpt-image-test', 'gpt-image-test'],
        ];
    }
}
