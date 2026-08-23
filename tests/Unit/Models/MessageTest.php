<?php

namespace Tests\Unit\Models;

use App\Enums\MessageRole;
use App\Models\Message;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    public function test_it_normalizes_unknown_source_roles(): void
    {
        $message = new Message(['role' => 'provider_specific_role']);

        Assert::assertSame(MessageRole::Unknown, $message->role);
        Assert::assertSame(MessageRole::Unknown->value, $message->getAttributes()['role']);
    }
}
