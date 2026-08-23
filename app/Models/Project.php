<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $created_at
 * @property string|null $emoji
 * @property int $id
 * @property array<array-key, mixed>|null $metadata
 * @property string $name
 * @property CarbonImmutable|null $updated_at
 * @property int $user_id
 * @property-read Collection<int, Conversation> $conversations
 * @property-read string $display_name
 * @property-read Collection<int, ProjectSourceIdentifier> $sourceIdentifiers
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class Project extends Model
{
    use BelongsToUser;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return Attribute<string, never> */
    protected function displayName(): Attribute
    {
        return Attribute::get(
            fn (): string => filled($this->emoji) ? $this->emoji.' '.$this->name : $this->name,
        );
    }

    /** @return HasMany<ProjectSourceIdentifier, $this> */
    public function sourceIdentifiers(): HasMany
    {
        return $this->hasMany(ProjectSourceIdentifier::class);
    }

    /** @return BelongsToMany<Conversation, $this> */
    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot('user_id')
            ->withTimestamps();
    }
}
