<?php

namespace App\Enums;

enum ProjectIdentifierType: string
{
    case ChatGptProject = 'chatgpt_project';
    case ChatGptGpt = 'chatgpt_gpt';
    case ChatGptWorkspace = 'chatgpt_workspace';
    case ChatGptTemplate = 'chatgpt_template';
    case CursorWorkspace = 'cursor_workspace';
    case CodexRepository = 'codex_repository';

    public function label(): string
    {
        return match ($this) {
            self::ChatGptProject => 'ChatGPT project',
            self::ChatGptGpt => 'ChatGPT GPT',
            self::ChatGptWorkspace => 'ChatGPT workspace',
            self::ChatGptTemplate => 'ChatGPT template',
            self::CursorWorkspace => 'Cursor workspace',
            self::CodexRepository => 'Codex repository',
        };
    }
}
