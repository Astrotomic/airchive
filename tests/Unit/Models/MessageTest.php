<?php

namespace Tests\Unit\Models;

use App\Enums\MessageRole;
use App\Models\Message;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class MessageTest extends UnitTestCase
{
    public function test_it_normalizes_unknown_source_roles(): void
    {
        $message = (new Message)->forceFill(['role' => 'provider_specific_role']);

        Assert::assertSame(MessageRole::Unknown, $message->role);
        Assert::assertSame(MessageRole::Unknown->value, $message->getAttributes()['role']);
    }

    public function test_it_casts_known_enum_and_string_roles_for_storage(): void
    {
        $message = (new Message)->forceFill(['role' => MessageRole::Developer]);

        Assert::assertSame(MessageRole::Developer, $message->role);
        Assert::assertSame('developer', $message->getAttributes()['role']);

        $message->role = ' USER ';

        Assert::assertSame(MessageRole::User, $message->role);
        Assert::assertSame('user', $message->getAttributes()['role']);
    }
}
