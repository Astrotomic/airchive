<?php

namespace App\Enums;

enum BlockType: string
{
    case Text = 'text';
    case Code = 'code';
    case Reasoning = 'reasoning';
    case ToolUse = 'tool_use';
    case ToolResult = 'tool_result';
    case Image = 'image';
    case Other = 'other';
}
