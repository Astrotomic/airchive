<?php

namespace App\Models;

use App\Enums\AttachmentCategory;
use App\Enums\AttachmentType;
use App\Enums\SourcePlatform;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Zenstruck\Bytes;

/**
 * @property AttachmentType $attachment_type
 * @property int|null $byte_size
 * @property string|null $checksum
 * @property int|null $content_block_id
 * @property int|null $conversation_id
 * @property CarbonImmutable|null $created_at
 * @property string|null $external_url
 * @property string|null $filename
 * @property int $id
 * @property int|null $message_id
 * @property string|null $mime_type
 * @property string|null $source_attachment_id
 * @property SourcePlatform|null $source_platform
 * @property array<array-key, mixed>|null $source_ref
 * @property string|null $storage_path
 * @property CarbonImmutable|null $updated_at
 * @property int $user_id
 * @property-read AttachmentCategory $category
 * @property-read ContentBlock|null $contentBlock
 * @property-read Conversation|null $conversation
 * @property-read string $extension_label
 * @property-read string $human_size
 * @property-read bool $is_available
 * @property-read Message|null $message
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
class Attachment extends Model
{
    use BelongsToUser;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_platform' => SourcePlatform::class,
            'attachment_type' => AttachmentType::class,
            'byte_size' => 'integer',
            'source_ref' => 'array',
        ];
    }

    /** @return Attribute<string, never> */
    protected function humanSize(): Attribute
    {
        return Attribute::get(
            fn (): string => $this->byte_size === null
                ? 'Unknown size'
                : (string) Bytes::parse($this->byte_size),
        );
    }

    /** @return Attribute<AttachmentCategory, never> */
    protected function category(): Attribute
    {
        return Attribute::make(get: function (): AttachmentCategory {
            $mime = Str::lower($this->mime_type ?? '');
            $extension = Str::lower(pathinfo($this->filename ?? '', PATHINFO_EXTENSION));
            $type = $this->attachment_type ?? AttachmentType::File;

            if ($type->isArtifact()) {
                return AttachmentCategory::Artifact;
            }

            if ($type === AttachmentType::Image || Str::startsWith($mime, 'image/')) {
                return AttachmentCategory::Image;
            }

            if (Str::startsWith($mime, 'audio/')) {
                return AttachmentCategory::Audio;
            }

            if (Str::startsWith($mime, 'video/')) {
                return AttachmentCategory::Video;
            }

            if ($mime === 'application/pdf' || $extension === 'pdf') {
                return AttachmentCategory::Pdf;
            }

            if (Str::startsWith($mime, 'text/') || in_array($mime, [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/x-yaml',
            ], true) || in_array($extension, ['txt', 'md', 'markdown', 'json', 'jsonl', 'xml', 'yaml', 'yml', 'csv', 'log'], true)) {
                return AttachmentCategory::Text;
            }

            if (in_array($extension, ['zip', 'tar', 'gz', 'tgz', '7z', 'rar'], true)
                || in_array($mime, ['application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed', 'application/vnd.rar'], true)) {
                return AttachmentCategory::Archive;
            }

            if (Str::contains($mime, ['word', 'excel', 'spreadsheet', 'powerpoint', 'presentation', 'opendocument'])
                || in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'], true)) {
                return AttachmentCategory::Document;
            }

            return AttachmentCategory::File;
        });
    }

    /** @return Attribute<string, never> */
    protected function extensionLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $extension = pathinfo($this->filename ?? '', PATHINFO_EXTENSION);

            return $extension !== '' ? Str::upper($extension) : $this->category->label();
        });
    }

    /** @return Attribute<bool, never> */
    protected function isAvailable(): Attribute
    {
        return Attribute::get(
            fn (): bool => filled($this->storage_path)
                || in_array(parse_url($this->external_url ?? '', PHP_URL_SCHEME), ['http', 'https'], true),
        );
    }

    public function textPreview(int $length = 900): ?string
    {
        if (! in_array($this->category, [AttachmentCategory::Text, AttachmentCategory::Artifact], true)
            || $this->storage_path === null) {
            return null;
        }

        if (! Storage::exists($this->storage_path)) {
            return null;
        }

        $stream = Storage::readStream($this->storage_path);

        if ($stream === false) {
            return null;
        }

        try {
            $contents = fread($stream, $length);
        } finally {
            fclose($stream);
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        return mb_scrub($contents, 'UTF-8');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<ContentBlock, $this>
     */
    public function contentBlock(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class);
    }
}
