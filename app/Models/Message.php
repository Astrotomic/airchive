<?php

namespace App\Models;

use App\Enums\MessageRole;
use App\Models\Casts\MessageRoleCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $actor_name
 * @property int $conversation_id
 * @property CarbonImmutable|null $created_at
 * @property int $id
 * @property bool $is_hidden
 * @property bool $is_on_canonical_path
 * @property array<array-key, mixed> $metadata
 * @property int|null $parent_message_id
 * @property MessageRole $role
 * @property string $source_message_id
 * @property string|null $updated_at
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Collection<int, ContentBlock> $contentBlocks
 * @property-read Conversation $conversation
 * @property-read Message|null $parent
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class Message extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MessageRoleCast::class,
            'created_at' => 'datetime',
            'is_on_canonical_path' => 'boolean',
            'is_hidden' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_message_id');
    }

    /**
     * @return HasMany<ContentBlock, $this>
     */
    public function contentBlocks(): HasMany
    {
        return $this->hasMany(ContentBlock::class)->orderBy('position');
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
