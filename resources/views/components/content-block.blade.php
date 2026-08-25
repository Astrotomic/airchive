@props (['block', 'collapseTools' => true])

@use ('App\Enums\BlockType')

@if ($block->block_type === BlockType::ToolUse || $block->block_type === BlockType::ToolResult)
    <details
        class="tool-block rounded-lg border border-zinc-200 bg-zinc-50"
        @unless ($collapseTools) open @endunless
    >
        <summary class="[&::-webkit-details-marker]:hidden flex cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm font-medium text-zinc-700">
            <span class="rounded bg-zinc-200 px-2 py-0.5 text-xs tracking-wide text-zinc-700 uppercase"> {{ $block->toolName() }} </span>
            <span class="min-w-0 truncate text-zinc-500">{{ $block->toolSummary() }}</span>
        </summary>
        <div class="border-t border-zinc-200">
            <pre class="json-block max-h-96 overflow-auto p-3 text-xs leading-relaxed text-zinc-800"><code><x-json :value="$block->structured_content['input'] ?? $block->structured_content['output'] ?? $block->structured_content ?? []" /></code></pre>
        </div>
    </details>
@elseif ($block->block_type === BlockType::Reasoning)
    <details
        class="rounded-lg border border-violet-200 bg-violet-50"
        @unless ($block->collapsedByDefault()) open @endunless
    >
        <summary class="cursor-pointer px-3 py-2 text-sm font-medium text-violet-900">Thinking</summary>
        <div class="message-content border-t border-violet-200 px-3 py-3 text-sm text-violet-950">{{ $block->text_content }}</div>
    </details>
@elseif ($block->block_type === BlockType::Code)
    <div class="max-w-full overflow-x-auto rounded-md bg-zinc-900">
        <pre class="p-3 text-zinc-100"><code class="block whitespace-pre-wrap break-words">{{ $block->text_content }}</code></pre>
    </div>
@elseif (filled($block->text_content))
    <div class="prose prose-sm prose-zinc max-w-none break-words">
        <x-markdown :content="$block->text_content" />
    </div>
@endif

@if ($block->attachments->isNotEmpty())
    <div class="mt-3 grid gap-3">
        @foreach ($block->attachments as $attachment)
            <x-attachment-preview :attachment="$attachment" />
        @endforeach
    </div>
@endif
