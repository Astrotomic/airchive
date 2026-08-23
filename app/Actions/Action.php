<?php

namespace App\Actions;

use Illuminate\Support\Traits\Conditionable;
use Mockery;
use Mockery\MockInterface;

abstract class Action
{
    use Conditionable;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function fake(): MockInterface
    {
        return app()->instance(static::class, Mockery::mock(static::class));
    }
}
