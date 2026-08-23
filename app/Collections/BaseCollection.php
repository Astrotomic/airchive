<?php

namespace App\Collections;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * @template TKey of array-key
 * @template TValue of Arrayable
 *
 * @extends Collection<TKey, TValue>
 *
 * @phpstan-consistent-constructor
 */
abstract class BaseCollection extends Collection
{
    public function toArray(): array
    {
        return $this
            ->map(fn (Arrayable $line) => $line->toArray())
            ->all();
    }

    /**
     * @param  static|iterable<TKey, TValue>  $source
     * @return static<TKey, TValue>
     */
    public function concat($source): static
    {
        return parent::concat($source);
    }

    /**
     * @template TGroupKey of array-key|\UnitEnum|\Stringable
     *
     * @param  (callable(TValue, TKey): TGroupKey)|string  $groupBy
     * @param  bool  $preserveKeys
     * @return Collection<
     *     ($groupBy is string
     *         ? array-key
     *         : (TGroupKey is \UnitEnum ? array-key : (TGroupKey is \Stringable ? string : array-key))),
     *     static<($preserveKeys is true ? TKey : int), TValue>
     * >
     */
    public function groupBy($groupBy, $preserveKeys = false): Collection
    {
        return $this
            ->toBase()
            ->groupBy($groupBy, $preserveKeys)
            ->map(fn (Collection $lines): static => $this->newGroup($lines));
    }

    /**
     * @template TGroupItemKey of array-key
     *
     * @param  Collection<TGroupItemKey, TValue>  $lines
     * @return static<TGroupItemKey, TValue>
     */
    private function newGroup(Collection $lines): static
    {
        return new static($lines);
    }

    /**
     * @template TMapValue
     *
     * @param  callable(TValue, TKey): TMapValue  $callback
     * @return Collection<TKey, TMapValue>
     */
    public function map(callable $callback): Collection
    {
        return $this
            ->toBase()
            ->map($callback);
    }

    /** @return Collection<int, TKey> */
    public function keys(): Collection
    {
        return $this
            ->toBase()
            ->keys();
    }

    /**
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param  callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue>  $callback
     * @return Collection<TMapWithKeysKey, TMapWithKeysValue>
     */
    public function mapWithKeys(callable $callback): Collection
    {
        return $this
            ->toBase()
            ->mapWithKeys($callback);
    }
}
