<?php

namespace App\Models;

use App\Enums\ProjectIdentifierType;
use App\Enums\SourcePlatform;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $created_at
 * @property int $id
 * @property ProjectIdentifierType $identifier_type
 * @property array<array-key, mixed>|null $metadata
 * @property int $project_id
 * @property string $source_identifier
 * @property SourcePlatform $source_platform
 * @property CarbonImmutable|null $updated_at
 * @property int $user_id
 * @property-read Project $project
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class ProjectSourceIdentifier extends Model
{
    use BelongsToUser;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_platform' => SourcePlatform::class,
            'identifier_type' => ProjectIdentifierType::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
