<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory as BaseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BaseFactory<TModel>
 */
abstract class Factory extends BaseFactory
{
    protected function store(Collection $results): void
    {
        parent::store($results);

        $results->each(fn (Model $model) => $model->refresh());
    }
}
