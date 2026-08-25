@use ('App\Enums\ExportFormat')
@use ('App\Enums\ExportMode')
@use ('Illuminate\Support\Str')

<div>
    <div class="mb-6">
        <h1 class="text-2xl font-semibold">Export</h1>
        <p class="mt-1 text-sm text-zinc-500">Download selected conversations and their directly attached files as a temporary ZIP.</p>
    </div>

    @if ($errors->any())
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('exports.download') }}"
        class="space-y-6"
    >
        @csrf

        <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">Choose conversations</h2>
            <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_12rem_18rem_auto] lg:items-end">
                <label>
                    <span class="mb-1 block text-xs text-zinc-500">Search</span>
                    <input
                        name="query"
                        value="{{ $query }}"
                        wire:model.live.debounce.300ms="query"
                        type="search"
                        placeholder="Titles and message content"
                        class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm"
                    />
                </label>
                <label>
                    <span class="mb-1 block text-xs text-zinc-500">Platform</span>
                    <select
                        name="platform"
                        wire:model.live="platform"
                        class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm"
                    >
                        <option value="">All platforms</option>
                        @foreach ($platforms as $platformCase)
                            <option value="{{ $platformCase->value }}">{{ $platformCase->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="mb-1 block text-xs text-zinc-500">Project</span>
                    <select
                        name="project"
                        wire:model.live="projectFilter"
                        class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm"
                    >
                        <option value="">All projects</option>
                        <option value="none">No project</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->display_name }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($query !== '' || $platform !== '' || $projectFilter !== '')
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm hover:bg-zinc-50"
                    >
                        Clear
                    </button>
                @endif
            </div>

            <div class="mt-5 border-t border-zinc-100 pt-4">
                <p class="text-sm font-medium">{{ number_format($conversations->total()) }} {{ Str::plural('conversation', $conversations->total()) }} will be exported</p>
                @if ($conversations->isNotEmpty())
                    <div class="mt-3 grid gap-2 lg:grid-cols-2">
                        @foreach ($conversations as $conversation)
                            <a
                                href="{{ route('conversations.show', $conversation) }}"
                                target="_blank"
                                class="rounded-md border border-zinc-100 px-3 py-2 hover:border-zinc-300"
                            >
                                <p class="truncate text-sm font-medium">{{ $conversation->title ?: 'Untitled conversation' }}</p>
                                <p class="mt-0.5 text-[11px] text-zinc-400">{{ $conversation->source_platform->label() }}</p>
                            </a>
                        @endforeach
                    </div>
                    @if ($conversations->hasPages())
                        <div class="mt-4">{{ $conversations->links() }}</div>
                    @endif
                @endif
            </div>
        </section>

        <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
            <h2 class="font-semibold">Export contents</h2>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <label>
                    <span class="mb-1 block text-xs text-zinc-500">Include</span>
                    <select
                        name="mode"
                        class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm"
                    >
                        @foreach (ExportMode::cases() as $mode)
                            <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    <span class="mb-1 block text-xs text-zinc-500">Chat format</span>
                    <select
                        name="format"
                        class="h-10 w-full rounded-md border border-zinc-300 bg-white px-3 text-sm"
                    >
                        @foreach (ExportFormat::cases() as $format)
                            <option value="{{ $format->value }}">{{ $format->label() }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </section>

        <div class="flex items-center justify-between gap-4">
            <p class="text-xs text-zinc-500">The temporary ZIP is deleted from the server after the download response.</p>
            <button
                type="submit"
                @disabled ($conversations->isEmpty())
                class="rounded-md bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-40"
            >
                Download ZIP
            </button>
        </div>
    </form>
</div>
