<?php

namespace App\Models;

use App\Enums\ImportFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $conversation_id
 * @property int $id
 * @property CarbonImmutable $imported_at
 * @property string $raw_checksum
 * @property string $raw_storage_path
 * @property string $source_file
 * @property ImportFormat $source_format
 * @property-read Conversation $conversation
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class ConversationSource extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_format' => ImportFormat::class,
            'imported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
