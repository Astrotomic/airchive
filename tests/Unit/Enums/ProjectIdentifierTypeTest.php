<?php

namespace Tests\Unit\Enums;

use App\Enums\ProjectIdentifierType;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ProjectIdentifierTypeTest extends UnitTestCase
{
    #[DataProvider('types')]
    public function test_it_defines_values_and_labels(
        ProjectIdentifierType $type,
        string $value,
        string $label,
    ): void {
        Assert::assertSame($value, $type->value);
        Assert::assertSame($label, $type->label());
    }

    /** @return iterable<string, array{ProjectIdentifierType, string, string}> */
    public static function types(): iterable
    {
        yield 'ChatGPT project' => [
            ProjectIdentifierType::ChatGptProject,
            'chatgpt_project',
            'ChatGPT project',
        ];
        yield 'ChatGPT GPT' => [
            ProjectIdentifierType::ChatGptGpt,
            'chatgpt_gpt',
            'ChatGPT GPT',
        ];
        yield 'ChatGPT workspace' => [
            ProjectIdentifierType::ChatGptWorkspace,
            'chatgpt_workspace',
            'ChatGPT workspace',
        ];
        yield 'ChatGPT template' => [
            ProjectIdentifierType::ChatGptTemplate,
            'chatgpt_template',
            'ChatGPT template',
        ];
        yield 'Cursor workspace' => [
            ProjectIdentifierType::CursorWorkspace,
            'cursor_workspace',
            'Cursor workspace',
        ];
        yield 'Codex repository' => [
            ProjectIdentifierType::CodexRepository,
            'codex_repository',
            'Codex repository',
        ];
    }
}
