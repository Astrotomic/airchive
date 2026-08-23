<?php

namespace App\Models;

use App\Enums\SourcePlatform;
use App\Models\Builders\ConversationBuilder;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\HasBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $canonical_leaf_message_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $first_message_at
 * @property int $id
 * @property CarbonImmutable|null $last_message_at
 * @property array<array-key, mixed> $metadata
 * @property string $source_conversation_id
 * @property SourcePlatform $source_platform
 * @property string|null $title
 * @property CarbonImmutable|null $updated_at
 * @property int $user_id
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Message|null $canonicalLeafMessage
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, ConversationSource> $sources
 * @property-read User $user
 *
 * @method static ConversationBuilder query()
 */
#[UseEloquentBuilder(ConversationBuilder::class)]
class Conversation extends Model
{
    use BelongsToUser;

    /** @use HasBuilder<ConversationBuilder> */
    use HasBuilder;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_platform' => SourcePlatform::class,
            'first_message_at' => 'datetime',
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<ConversationSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ConversationSource::class);
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('user_id')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function canonicalLeafMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'canonical_leaf_message_id');
    }
}
