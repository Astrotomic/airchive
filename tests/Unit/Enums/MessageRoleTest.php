<?php

namespace Tests\Unit\Enums;

use App\Enums\MessageRole;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class MessageRoleTest extends UnitTestCase
{
    #[DataProvider('normalizedValues')]
    public function test_it_normalizes_roles(mixed $value, MessageRole $expected): void
    {
        Assert::assertSame($expected, MessageRole::normalize($value));
    }

    /** @return iterable<string, array{mixed, MessageRole}> */
    public static function normalizedValues(): iterable
    {
        yield 'normalized user' => [' USER ', MessageRole::User];
        yield 'assistant' => ['assistant', MessageRole::Assistant];
        yield 'system' => ['system', MessageRole::System];
        yield 'tool' => ['tool', MessageRole::Tool];
        yield 'developer' => ['developer', MessageRole::Developer];
        yield 'explicit unknown' => ['unknown', MessageRole::Unknown];
        yield 'unsupported string' => ['admin', MessageRole::Unknown];
        yield 'non-string' => [123, MessageRole::Unknown];
    }

    #[DataProvider('roles')]
    public function test_it_defines_role_presentation(
        MessageRole $role,
        string $value,
        string $label,
        bool $hasDedicatedStyle,
    ): void {
        Assert::assertSame($value, $role->value);
        Assert::assertSame($label, $role->label());
        Assert::assertSame($hasDedicatedStyle, $role->hasDedicatedStyle());
    }

    /** @return iterable<string, array{MessageRole, string, string, bool}> */
    public static function roles(): iterable
    {
        yield 'user' => [MessageRole::User, 'user', 'User', true];
        yield 'assistant' => [MessageRole::Assistant, 'assistant', 'Assistant', true];
        yield 'system' => [MessageRole::System, 'system', 'System', true];
        yield 'tool' => [MessageRole::Tool, 'tool', 'Tool', true];
        yield 'developer' => [MessageRole::Developer, 'developer', 'Developer', false];
        yield 'unknown' => [MessageRole::Unknown, 'unknown', 'Unknown', false];
    }
}
