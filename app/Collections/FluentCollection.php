<?php

namespace App\Collections;

use App\ValueObjects\Fluent;

/**
 * @extends BaseCollection<array-key, Fluent>
 */
final class FluentCollection extends BaseCollection
{
    public static function from(array $data): self
    {
        $items = collect($data)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): ?Fluent => Fluent::tryFrom($item))
            ->filter();

        return new self($items);
    }

    public function __construct($items = [])
    {
        parent::__construct($items);

        $this->ensure(Fluent::class);
    }
}
