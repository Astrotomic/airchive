<?php

namespace App\Models;

use App\Actions\Imports\SummarizeCursorTool;
use App\Enums\BlockType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BlockType $block_type
 * @property CarbonImmutable|null $created_at
 * @property int $id
 * @property string|null $language
 * @property int $message_id
 * @property array<array-key, mixed>|null $metadata
 * @property int $position
 * @property array<array-key, mixed>|null $structured_content
 * @property string|null $text_content
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Attachment> $attachments
 * @property-read Message $message
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class ContentBlock extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'block_type' => BlockType::class,
            'structured_content' => 'array',
            'metadata' => 'array',
        ];
    }

    public function toolName(): string
    {
        $structured = $this->structured_content ?? [];

        return (string) (
            $this->metadata['tool_name']
            ?? $structured['name']
            ?? 'Tool'
        );
    }

    public function toolSummary(): string
    {
        $structured = $this->structured_content ?? [];
        $input = $structured['input'] ?? $structured;

        return SummarizeCursorTool::make()->execute(
            $this->toolName(),
            is_array($input) ? $input : null,
        );
    }

    public function isToolBlock(): bool
    {
        return in_array($this->block_type, [BlockType::ToolUse, BlockType::ToolResult], true);
    }

    public function collapsedByDefault(): bool
    {
        if ($this->block_type === BlockType::Reasoning) {
            return true;
        }

        return (bool) ($this->metadata['collapsed_by_default'] ?? $this->isToolBlock());
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
