<div>
    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Conversations</h1>
        <select
            wire:model.live="platform"
            class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
        >
            <option value="">All platforms</option>
            @foreach ($platforms as $platformCase)
                <option value="{{ $platformCase->value }}">{{ ucfirst($platformCase->value) }}</option>
            @endforeach
        </select>
    </div>

    @if ($conversations->isEmpty())
        <div class="rounded-lg border border-dashed border-zinc-300 bg-white p-10 text-center text-zinc-500">
            No conversations yet.
            <a
                href="{{ route('imports.upload') }}"
                class="text-zinc-900 underline"
                >Import an export</a
            >
            to get started.
        </div>
    @else
        <div class="space-y-3">
            @foreach ($conversations as $conversation)
                <a
                    href="{{ route('conversations.show', $conversation) }}"
                    class="block rounded-lg border border-zinc-200 bg-white p-4 hover:border-zinc-400"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="font-medium">{{ $conversation->title ?: 'Untitled conversation' }}</h2>
                            <p class="mt-1 text-sm text-zinc-500">{{ $conversation->source_platform->label() }} · {{ $conversation->last_message_at ? 'last message '.$conversation->last_message_at->diffForHumans() : 'message time unavailable' }}</p>
                            @if ($conversation->projects->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($conversation->projects as $project)
                                        <span class="rounded bg-violet-50 px-2 py-1 text-[10px] text-violet-800">{{ $project->display_name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-6">{{ $conversations->links() }}</div>
    @endif
</div>
