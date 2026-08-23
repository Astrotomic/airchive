@use('Illuminate\Support\Str')


<div>
    <h1 class="mb-6 text-2xl font-semibold">Search</h1>

    <div class="mb-6 grid gap-3 lg:grid-cols-[minmax(0,1fr)_12rem_18rem_auto] lg:items-end">
        <label>
            <span class="mb-1 block text-xs text-zinc-500">Search</span>
            <input type="search" wire:model.live.debounce.300ms="query" placeholder="Titles and message content" class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm">
        </label>
        <label>
            <span class="mb-1 block text-xs text-zinc-500">Platform</span>
            <select wire:model.live="platform" class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm">
                <option value="">All platforms</option>
                @foreach ($platforms as $platformCase)
                    <option value="{{ $platformCase->value }}">{{ $platformCase->label() }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span class="mb-1 block text-xs text-zinc-500">Project</span>
            <select wire:model.live="projectFilter" class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm">
                <option value="">All projects</option>
                <option value="none">No project</option>
                @foreach ($projects as $projectOption)
                    <option value="{{ $projectOption->id }}">{{ $projectOption->display_name }}</option>
                @endforeach
            </select>
        </label>
        @if ($query !== '' || $platform !== '' || $projectFilter !== '')
            <button type="button" wire:click="clearFilters" class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm hover:bg-zinc-50">Clear</button>
        @endif
    </div>

    @if ($query === '' && $platform === '' && $projectFilter === '')
        <p class="text-sm text-zinc-500">Enter a query or choose filters to find conversations.</p>
    @elseif ($results->isEmpty())
        <p class="text-sm text-zinc-500">No conversations matched the current search and filters.</p>
    @else
        <p class="mb-4 text-sm text-zinc-500">{{ number_format($results->total()) }} matching {{ Str::plural('conversation', $results->total()) }}</p>
        <div class="space-y-3">
            @foreach ($results as $conversation)
                <a href="{{ route('conversations.show', $conversation) }}" class="block rounded-lg border border-zinc-200 bg-white p-4 hover:border-zinc-400">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="break-words font-medium">{{ $conversation->title ?: 'Untitled conversation' }}</h2>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $conversation->source_platform->label() }}
                                · {{ $conversation->last_message_at ? 'last message '.$conversation->last_message_at->diffForHumans() : 'message time unavailable' }}
                            </p>
                        </div>
                        @if ($conversation->projects->isNotEmpty())
                            <div class="flex max-w-full flex-wrap justify-end gap-1.5">
                                @foreach ($conversation->projects as $project)
                                    <span class="rounded bg-violet-50 px-2 py-1 text-[10px] text-violet-800">{{ $project->display_name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $results->links() }}
        </div>
    @endif
</div>
