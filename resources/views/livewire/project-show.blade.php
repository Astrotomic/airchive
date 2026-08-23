@use('App\Enums\SourcePlatform')


<div>
    <a href="{{ route('projects.index') }}" class="text-sm text-zinc-500 hover:text-zinc-800">← Projects</a>

    <div class="mt-2 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0">
            <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="break-words text-2xl font-semibold">{{ $project->display_name }}</h1>
                    <p class="mt-1 text-sm text-zinc-500">
                        @if ($platform !== '' || $search !== '')
                            {{ $conversations->total() }} of {{ $projectConversationCount }} conversations
                        @else
                            {{ $projectConversationCount }} conversations
                        @endif
                        · {{ $identifiers->count() }} source identifiers
                    </p>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <label>
                        <span class="mb-1 block text-xs text-zinc-500">Search this project</span>
                        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Titles and messages" class="h-10 w-64 max-w-full rounded-md border border-zinc-300 bg-white px-3 text-sm">
                    </label>
                    @if ($platforms->count() > 1)
                        <label>
                            <span class="mb-1 block text-xs text-zinc-500">Platform</span>
                            <select wire:model.live="platform" class="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm">
                                <option value="">All platforms ({{ $projectConversationCount }})</option>
                                @foreach ($platforms as $platformCase)
                                    <option value="{{ $platformCase->value }}">
                                        {{ $platformCase->label() }} ({{ $platformCounts[$platformCase->value] }})
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                </div>
            </div>

            @if ($conversations->isEmpty())
                <div class="rounded-lg border border-dashed border-zinc-300 bg-white p-10 text-center text-zinc-500">
                    {{ $platform !== '' || $search !== '' ? 'No conversations match the current search and filters.' : 'No conversations in this project.' }}
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($conversations as $conversation)
                        <a wire:key="conversation-{{ $conversation->id }}" href="{{ route('conversations.show', $conversation) }}" class="block rounded-lg border border-zinc-200 bg-white p-4 hover:border-zinc-400">
                            <h2 class="font-medium">{{ $conversation->title ?: 'Untitled conversation' }}</h2>
                            <p class="mt-1 text-sm text-zinc-500">{{ $conversation->source_platform->label() }} · {{ $conversation->last_message_at ? 'last message '.$conversation->last_message_at->diffForHumans() : 'message time unavailable' }}</p>
                            @if ($conversation->projects->count() > 1)
                                <p class="mt-2 text-xs text-zinc-400">Also in {{ $conversation->projects->where('id', '!=', $project->id)->map(fn ($otherProject) => $otherProject->display_name)->join(', ') }}</p>
                            @endif
                        </a>
                    @endforeach
                </div>
                <div class="mt-6">{{ $conversations->links() }}</div>
            @endif
        </div>

        <aside class="space-y-5 xl:sticky xl:top-6 xl:self-start">
            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold">Project appearance</h2>
                <form wire:submit="rename" class="mt-3 space-y-2">
                    <div class="grid grid-cols-[4.5rem_minmax(0,1fr)] gap-2">
                        <label>
                            <span class="mb-1 block text-xs text-zinc-500">Icon</span>
                            <input wire:model="emoji" type="text" maxlength="32" placeholder="📁" class="h-10 w-full rounded-md border border-zinc-300 px-3 text-center text-lg leading-none">
                        </label>
                        <label>
                            <span class="mb-1 block text-xs text-zinc-500">Name</span>
                            <input wire:model="name" type="text" class="h-10 w-full rounded-md border border-zinc-300 px-3 text-sm">
                        </label>
                    </div>
                    @error('emoji') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    @error('name') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <p class="text-xs text-zinc-400">The icon is displayed with the name but does not affect sorting.</p>
                    <button type="submit" class="rounded-md bg-zinc-900 px-3 py-2 text-sm text-white hover:bg-zinc-700">Save project</button>
                </form>
            </section>

            <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                <h2 class="font-semibold">Source identifiers</h2>
                <p class="mt-1 text-xs text-zinc-500">Any future chat matching one of these is added to this project.</p>
                <div class="mt-3 space-y-2">
                    @foreach ($identifiers as $identifier)
                        <div wire:key="identifier-{{ $identifier->id }}" class="rounded-md bg-zinc-50 p-3">
                            <div class="flex flex-wrap gap-1.5 text-[10px]">
                                <span class="rounded bg-white px-1.5 py-0.5 text-zinc-700">{{ $identifier->source_platform->label() }}</span>
                                <span class="rounded bg-white px-1.5 py-0.5 text-zinc-500">{{ $identifier->identifier_type->label() }}</span>
                            </div>
                            <p class="mt-2 break-all font-mono text-[10px] text-zinc-700">{{ $identifier->source_identifier }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            @if ($mergeCandidates->isNotEmpty())
                <section class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm">
                    <h2 class="font-semibold">Merge projects</h2>
                    <p class="mt-1 text-xs text-zinc-500">Move another project’s chats and source identifiers into this one.</p>
                    <form wire:submit="merge" class="mt-3 space-y-2">
                        <select wire:model="mergeProjectId" class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm">
                            <option value="">Choose a project</option>
                            @foreach ($mergeCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->display_name }} ({{ $candidate->conversations_count }})</option>
                            @endforeach
                        </select>
                        @error('mergeProjectId') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" wire:confirm="Merge this project into {{ $project->display_name }}? Its source identifiers will be kept." class="rounded-md border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50">Merge into this project</button>
                    </form>
                </section>
            @endif
        </aside>
    </div>
</div>
