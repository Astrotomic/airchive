<?php

namespace App\Models\Casts;

use App\Enums\MessageRole;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<MessageRole, MessageRole|string> */
final class MessageRoleCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): MessageRole
    {
        return MessageRole::normalize($value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value instanceof MessageRole
            ? $value->value
            : MessageRole::normalize($value)->value;
    }
}
