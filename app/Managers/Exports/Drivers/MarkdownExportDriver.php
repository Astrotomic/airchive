<?php

namespace App\Managers\Exports\Drivers;

use App\Contracts\ConversationExportDriver;
use App\Enums\BlockType;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

final class MarkdownExportDriver implements ConversationExportDriver
{
    public function export(Conversation $conversation): string
    {
        $lines = ['# '.$this->escapeMarkdownInline($conversation->title ?? 'Untitled conversation'), ''];

        foreach ($this->canonicalMessages($conversation) as $message) {
            $lines[] = '## '.$this->messageHeading($message);
            $lines[] = '';

            foreach ($message->contentBlocks as $block) {
                $lines[] = $this->renderBlock($block);
                $lines[] = '';
            }
        }

        return rtrim(implode("\n", $lines))."\n";
    }

    /**
     * @return Collection<int, Message>
     */
    private function canonicalMessages(Conversation $conversation): Collection
    {
        return $conversation->messages()
            ->where('is_on_canonical_path', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->with(['contentBlocks.attachments'])
            ->get();
    }

    private function messageHeading(Message $message): string
    {
        $role = $message->role->label();

        if ($message->actor_name) {
            return $role.' ('.$message->actor_name.')';
        }

        return $role;
    }

    private function renderBlock(ContentBlock $block): string
    {
        return match ($block->block_type) {
            BlockType::Code => $this->renderCodeBlock($block),
            BlockType::Image => $this->renderImageBlock($block),
            BlockType::ToolUse => "**Tool use**\n\n".($block->text_content ?? ''),
            BlockType::ToolResult => "**Tool result**\n\n".($block->text_content ?? ''),
            BlockType::Reasoning => "_Reasoning:_\n\n".($block->text_content ?? ''),
            default => (string) ($block->text_content ?? ''),
        };
    }

    private function renderCodeBlock(ContentBlock $block): string
    {
        $language = $block->language ?? '';

        return '```'.$language."\n".($block->text_content ?? '')."\n```";
    }

    private function renderImageBlock(ContentBlock $block): string
    {
        $url = $block->text_content ?? $block->attachments->first()->external_url ?? '';

        return $url !== '' ? '![]('.$url.')' : '';
    }

    private function escapeMarkdownInline(string $value): string
    {
        return str_replace(['#'], ['\\#'], $value);
    }
}
