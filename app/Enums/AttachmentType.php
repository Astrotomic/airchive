<?php

namespace App\Enums;

enum AttachmentType: string
{
    case File = 'file';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Canvas = 'canvas';
    case AgentTool = 'agent_tool';
    case Terminal = 'terminal';

    public static function fromMimeType(?string $mimeType): self
    {
        return match (true) {
            str_starts_with((string) $mimeType, 'image/') => self::Image,
            str_starts_with((string) $mimeType, 'audio/') => self::Audio,
            str_starts_with((string) $mimeType, 'video/') => self::Video,
            default => self::File,
        };
    }

    public function isArtifact(): bool
    {
        return match ($this) {
            self::Canvas, self::AgentTool, self::Terminal => true,
            default => false,
        };
    }
}
