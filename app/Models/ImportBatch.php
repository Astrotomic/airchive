<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportFormat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $created_at
 * @property ImportFormat|null $detected_format
 * @property string|null $error_message
 * @property string $file_path
 * @property CarbonImmutable|null $finished_at
 * @property int $id
 * @property CarbonImmutable|null $started_at
 * @property ImportBatchStatus $status
 * @property CarbonImmutable|null $updated_at
 * @property int $user_id
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class ImportBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'detected_format' => ImportFormat::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
