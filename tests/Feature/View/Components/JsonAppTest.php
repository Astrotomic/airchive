<?php

namespace Tests\Feature\View\Components;

use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class JsonAppTest extends AppTestCase
{
    public function test_it_pretty_prints_json_values(): void
    {
        $json = $this->renderJson([
            'url' => 'https://example.com/über',
            'enabled' => true,
        ]);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        ArrayAssertions::assertAssociative($decoded);
        Assert::assertSame(<<<'JSON'
{
    "url": "https://example.com/über",
    "enabled": true
}
JSON, $json);
    }

    public function test_it_decodes_json_strings_before_formatting_them(): void
    {
        $json = $this->renderJson('{"nested":{"enabled":true}}');
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        ArrayAssertions::assertAssociative($decoded);
        ArrayAssertions::assertAssociative($decoded['nested']);
        Assert::assertSame(<<<'JSON'
{
    "nested": {
        "enabled": true
    }
}
JSON, $json);
    }

    public function test_it_preserves_invalid_json_strings(): void
    {
        Assert::assertSame('<invalid>', $this->renderJson('<invalid>'));
    }

    public function test_it_returns_empty_output_for_values_json_cannot_encode(): void
    {
        Assert::assertSame('', $this->renderJson(INF));
    }

    private function renderJson(mixed $value): string
    {
        $html = Blade::render('<x-json :value="$value" />', ['value' => $value]);

        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
