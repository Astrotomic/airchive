<?php

namespace App\Enums;

enum AttachmentCategory: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Pdf = 'pdf';
    case Text = 'text';
    case Archive = 'archive';
    case Document = 'document';
    case Artifact = 'artifact';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Audio => 'Audio',
            self::Video => 'Video',
            self::Pdf => 'PDF',
            self::Text => 'Text',
            self::Archive => 'Archive',
            self::Document => 'Document',
            self::Artifact => 'Artifact',
            self::File => 'File',
        };
    }
}
