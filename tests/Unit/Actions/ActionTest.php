<?php

namespace Tests\Unit\Actions;

use App\Actions\Action;
use Mockery\MockInterface;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class ActionTest extends UnitTestCase
{
    public function test_make_resolves_the_concrete_action_from_the_container(): void
    {
        Assert::assertInstanceOf(ExampleAction::class, ExampleAction::make());
    }

    public function test_fake_replaces_the_concrete_action_in_the_container(): void
    {
        $fake = ExampleAction::fake();

        Assert::assertInstanceOf(MockInterface::class, $fake);
        Assert::assertSame($fake, ExampleAction::make());
    }
}

class ExampleAction extends Action
{
    public function execute(): string
    {
        return 'example';
    }
}
