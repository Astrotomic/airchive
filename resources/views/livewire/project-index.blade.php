@use('Illuminate\Support\Str')


<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Projects</h1>
            <p class="mt-1 text-sm text-zinc-500">Chats grouped by their source project, GPT, workspace, or repository.</p>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2">
            @if ($projects->isNotEmpty())
                <button type="button" wire:click="selectVisible(@json($projects->pluck('id')->values()))" class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm hover:bg-zinc-50">Select this page</button>
            @endif
            <label class="block">
                <span class="sr-only">Search projects</span>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search projects or source IDs" class="w-72 max-w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm">
            </label>
        </div>
    </div>

    @if ($selectedProjects->isNotEmpty())
        <section class="mb-6 rounded-xl border border-violet-200 bg-violet-50 p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="font-medium text-violet-950">{{ $selectedProjects->count() }} {{ Str::plural('project', $selectedProjects->count()) }} selected</p>
                    <p class="mt-1 text-xs text-violet-700">Selections stay active while you search or change pages.</p>
                </div>
                @if ($selectedProjects->count() >= 2)
                    <div class="flex flex-wrap items-end gap-2">
                        <label>
                            <span class="mb-1 block text-xs text-violet-700">Keep as merged project</span>
                            <select wire:model.live="mergeTargetId" class="h-10 min-w-56 rounded-md border border-violet-300 bg-white px-3 text-sm">
                                @foreach ($selectedProjects as $selectedProject)
                                    <option value="{{ $selectedProject->id }}">{{ $selectedProject->display_name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="button" wire:click="mergeSelected" wire:confirm="Merge all selected projects? Their chats and source identifiers will be moved into the project you chose to keep." class="h-10 rounded-md border border-violet-950 bg-violet-900 px-4 text-sm font-semibold text-white shadow-sm hover:bg-violet-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-700">Merge selected</button>
                        <button type="button" wire:click="clearSelection" class="h-10 rounded-md border border-violet-300 bg-white px-3 text-sm text-violet-900 hover:bg-violet-100">Clear</button>
                    </div>
                @else
                    <div class="flex h-10 flex-wrap items-center gap-3">
                        <p class="text-sm text-violet-700">Select at least one more project to merge.</p>
                        <button type="button" wire:click="clearSelection" class="h-10 rounded-md border border-violet-300 bg-white px-3 text-sm text-violet-900 hover:bg-violet-100">Clear</button>
                    </div>
                @endif
            </div>
            @error('selectedProjectIds') <p class="mt-2 text-xs text-red-700">{{ $message }}</p> @enderror
            @error('mergeTargetId') <p class="mt-2 text-xs text-red-700">{{ $message }}</p> @enderror
        </section>
    @endif

    @if ($projects->isEmpty())
        <div class="rounded-lg border border-dashed border-zinc-300 bg-white p-10 text-center text-zinc-500">
            @if ($search !== '')
                No matching projects.
            @else
                Projects are created automatically when an import contains a project, GPT, workspace, or repository identifier.
            @endif
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $project)
                <article wire:key="project-{{ $project->id }}" @class(['rounded-xl border bg-white p-5 shadow-sm', 'border-violet-400 ring-2 ring-violet-100' => in_array($project->id, $selectedProjectIds, true), 'border-zinc-200' => ! in_array($project->id, $selectedProjectIds, true)])>
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <input wire:model.live="selectedProjectIds" type="checkbox" value="{{ $project->id }}" aria-label="Select {{ $project->display_name }}" class="mt-1 rounded border-zinc-300 text-violet-700 focus:ring-violet-600">
                            <a href="{{ route('projects.show', $project) }}" class="min-w-0 break-words font-semibold hover:underline">{{ $project->display_name }}</a>
                        </div>
                        <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-1 text-[11px] text-zinc-600">{{ $project->conversations_count }} chats</span>
                    </div>
                    @if ($projectPlatformIdentifierCounts[$project->id]->isNotEmpty())
                        <p class="mt-4 text-xs text-zinc-500">
                            @foreach ($projectPlatformIdentifierCounts[$project->id] as $source)
                                @if (! $loop->first) <span class="mx-1 text-zinc-300">|</span> @endif
                                {{ $source['count'] }} {{ $source['label'] }}
                            @endforeach
                        </p>
                    @endif
                </article>
            @endforeach
        </div>

        <div class="mt-6">{{ $projects->links() }}</div>
    @endif
</div>
