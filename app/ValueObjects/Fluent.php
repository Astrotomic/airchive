<?php

namespace App\ValueObjects;

use App\Collections\FluentCollection;
use Illuminate\Support\Fluent as IlluminateFluent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use TypeError;

/** @extends IlluminateFluent<string, mixed> */
final class Fluent extends IlluminateFluent
{
    public static function tryFrom(mixed $data): ?static
    {
        if (! is_string($data) && ! is_array($data)) {
            return null;
        }

        try {
            return self::from($data);
        } catch (Throwable) {
            return null;
        }
    }

    public static function from(string|array $data): static
    {
        if (is_array($data)) {
            return new static($data);
        }

        if (! Str::isJson($data)) {
            throw new InvalidArgumentException('Data must be an array or JSON string.');
        }

        $decoded = json_decode($data, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JSON data must decode to an array.');
        }

        return new static($decoded);
    }

    /**
     * @return non-empty-string|null
     */
    public function nullString(string $key, ?string $default = null): ?string
    {
        $value = $this->get($key, $default);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    public function scalarString(string $key): ?string
    {
        $value = $this->get($key);

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        return trim((string) $value);
    }

    /** @return array<array-key, mixed>|null */
    public function nullArray(string $key): ?array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : null;
    }

    public function nullInteger(string $key): ?int
    {
        $value = $this->get($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    public function nullFluent(string $key): ?self
    {
        $value = $this->get($key);

        return is_array($value) ? new self($value) : null;
    }

    public function fluent(string $key): self
    {
        return $this->nullFluent($key) ?? new self;
    }

    public function collectFluent(string $key): FluentCollection
    {
        return FluentCollection::from($this->array($key));
    }
}
