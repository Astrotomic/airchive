<?php

namespace App\Managers\Exports\Drivers;

use App\Contracts\ConversationExportDriver;
use App\Enums\BlockType;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

final class HtmlExportDriver implements ConversationExportDriver
{
    public function export(Conversation $conversation): string
    {
        $title = $this->escape($conversation->title ?? 'Untitled conversation');
        $sections = [];

        foreach ($this->canonicalMessages($conversation) as $message) {
            $blocks = [];

            foreach ($message->contentBlocks as $block) {
                $blocks[] = $this->renderBlock($block);
            }

            $sections[] = sprintf(
                '<section class="message message-%s"><h2>%s</h2>%s</section>',
                $this->escape($message->role->value),
                $this->escape($this->messageHeading($message)),
                implode('', $blocks),
            );
        }

        $body = implode("\n", $sections);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
</head>
<body>
    <article class="conversation">
        <h1>{$title}</h1>
        {$body}
    </article>
</body>
</html>
HTML;
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
            BlockType::Code => sprintf(
                '<pre><code class="language-%s">%s</code></pre>',
                $this->escape($block->language ?? ''),
                $this->escape($block->text_content ?? ''),
            ),
            BlockType::Image => $this->renderImageBlock($block),
            BlockType::ToolUse => '<div class="tool-use"><strong>Tool use</strong><pre>'
                .$this->escape($block->text_content ?? '').'</pre></div>',
            BlockType::ToolResult => '<div class="tool-result"><strong>Tool result</strong><pre>'
                .$this->escape($block->text_content ?? '').'</pre></div>',
            BlockType::Reasoning => '<div class="reasoning"><em>Reasoning</em><p>'
                .$this->escape($block->text_content ?? '').'</p></div>',
            default => '<p>'.$this->escape($block->text_content ?? '').'</p>',
        };
    }

    private function renderImageBlock(ContentBlock $block): string
    {
        $url = $block->text_content ?? $block->attachments->first()->external_url ?? '';

        if ($url === '') {
            return '';
        }

        return sprintf('<img src="%s" alt="">', $this->escape($url));
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
