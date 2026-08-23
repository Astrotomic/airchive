<?php

namespace App\Enums;

enum ExportMode: string
{
    case ChatsAndFiles = 'chats_and_files';
    case ChatsOnly = 'chats_only';
    case FilesOnly = 'files_only';

    public function label(): string
    {
        return match ($this) {
            self::ChatsAndFiles => 'Chats and attached files',
            self::ChatsOnly => 'Chats only',
            self::FilesOnly => 'Attached files only',
        };
    }

    public function includesChats(): bool
    {
        return $this !== self::FilesOnly;
    }

    public function includesFiles(): bool
    {
        return $this !== self::ChatsOnly;
    }
}
