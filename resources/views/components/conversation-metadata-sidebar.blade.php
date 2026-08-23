<aside class="order-first min-w-0 xl:order-last xl:sticky xl:top-6 xl:max-h-[calc(100vh-3rem)] xl:overflow-y-auto">
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm">
        <div class="border-b border-zinc-200 px-4 py-3">
            <h2 class="font-semibold">Conversation details</h2>
        </div>

        <section class="border-b border-zinc-200 p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Information</h3>
            <dl class="mt-3 space-y-2 text-xs">
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">Platform</dt><dd class="font-medium">{{ $conversation->source_platform->label() }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">Started</dt><dd class="text-right font-medium">{{ $stats['started_at']?->toDayDateTimeString() ?: '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-zinc-500">Ended</dt><dd class="text-right font-medium">{{ $stats['ended_at']?->toDayDateTimeString() ?: '—' }}</dd></div>
            </dl>
        </section>

        <section class="border-b border-zinc-200 p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Messages</h3>
            <div class="mt-3 flex flex-wrap gap-1.5">
                @foreach ($stats['roles'] as $role => $count)
                    <span @class([
                        'rounded-full px-2 py-1 text-[11px]',
                        'bg-blue-100 text-blue-800' => $role === 'user',
                        'bg-emerald-100 text-emerald-800' => $role === 'assistant',
                        'bg-zinc-100 text-zinc-700' => ! in_array($role, ['user', 'assistant'], true),
                    ])>{{ ucfirst($role) }} {{ $count }}</span>
                @endforeach
                @if ($stats['hidden_count'] > 0)
                    <span class="rounded-full bg-amber-50 px-2 py-1 text-[11px] text-amber-700">Hidden {{ $stats['hidden_count'] }}</span>
                @endif
            </div>
        </section>

        <section class="border-b border-zinc-200 p-4">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Models used</h3>
            @if ($models->isEmpty())
                <p class="mt-2 text-xs text-zinc-400">No model metadata available.</p>
            @else
                <div class="mt-2 flex flex-wrap gap-1.5">
                    @foreach ($models as $model)
                        <span class="break-all rounded bg-violet-50 px-2 py-1 font-mono text-[10px] text-violet-800">{{ $model }}</span>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="border-b border-zinc-200 p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Projects</h3>
                <a href="{{ route('projects.index') }}" class="text-[11px] text-zinc-500 hover:text-zinc-900">All projects →</a>
            </div>
            @if ($projects->isEmpty())
                <p class="mt-2 text-xs text-zinc-400">No project assigned.</p>
                @if ($projectAssignmentOptions->isNotEmpty())
                    <form wire:submit="assignProject" class="mt-3 space-y-2">
                        <select wire:model="projectToAssign" class="h-9 w-full rounded-md border border-zinc-300 bg-white px-2 text-xs">
                            <option value="">Choose a project</option>
                            @foreach ($projectAssignmentOptions as $projectOption)
                                <option value="{{ $projectOption->id }}">{{ $projectOption->display_name }}</option>
                            @endforeach
                        </select>
                        @error('projectToAssign') <p class="text-[11px] text-red-600">{{ $message }}</p> @enderror
                        <button type="submit" class="h-9 w-full rounded-md bg-zinc-900 px-3 text-xs font-medium text-white hover:bg-zinc-700">Add to project</button>
                    </form>
                @else
                    <p class="mt-2 text-[11px] text-zinc-400">No projects are available yet.</p>
                @endif
            @else
                <div class="mt-2 space-y-1.5">
                    @foreach ($projects as $project)
                        <a href="{{ route('projects.show', $project) }}" class="block rounded-md bg-violet-50 px-2.5 py-2 text-xs font-medium text-violet-800 hover:bg-violet-100">{{ $project->display_name }}</a>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($urls->isNotEmpty())
            <section class="border-b border-zinc-200 p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Links</h3>
                <div class="mt-2 space-y-1.5">
                    @foreach ($urls as $url)
                        <a href="{{ $url['url'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-2 rounded-md bg-zinc-50 px-2.5 py-2 text-xs text-zinc-700 hover:bg-zinc-100">
                            <span>{{ $url['label'] }}</span>
                            <span aria-hidden="true">↗</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($externalSources->isNotEmpty())
            <section class="border-b border-zinc-200 p-4">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">External sources</h3>
                    <span class="text-[11px] tabular-nums text-zinc-400">{{ $externalSourceCount }}</span>
                </div>
                <div class="mt-2 space-y-2">
                    @foreach ($externalSources as $externalSourceGroup)
                        <details class="group overflow-hidden rounded-md border border-zinc-200 bg-zinc-50">
                            <summary class="flex cursor-pointer list-none items-center gap-2 px-2.5 py-2 text-xs font-medium text-zinc-700 hover:bg-zinc-100 [&::-webkit-details-marker]:hidden">
                                <span class="relative flex h-6 w-6 shrink-0 items-center justify-center overflow-hidden rounded-full border border-zinc-200 bg-white text-[9px] font-semibold uppercase text-zinc-400">
                                    {{ $sourceInitial($externalSourceGroup['domain']) }}
                                    <img src="{{ $externalSourceGroup['favicon_url'] }}" alt="" loading="lazy" referrerpolicy="origin" class="absolute inset-0 h-full w-full bg-white object-cover" onerror="this.remove()">
                                </span>
                                <span class="min-w-0 flex-1 truncate">{{ $externalSourceGroup['domain'] }}</span>
                                <span class="text-[10px] tabular-nums text-zinc-400">{{ $externalSourceGroup['links']->count() }}</span>
                                <span class="text-zinc-400 transition group-open:rotate-90" aria-hidden="true">›</span>
                            </summary>
                            <div class="space-y-0.5 border-t border-zinc-200 bg-white p-1.5">
                                @foreach ($externalSourceGroup['links'] as $externalSource)
                                    <a href="{{ $externalSource['url'] }}" target="_blank" rel="noopener noreferrer" title="{{ $externalSource['url'] }}" class="flex min-w-0 items-center gap-2 rounded px-2 py-1.5 text-xs text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900">
                                        <span class="shrink-0 text-zinc-400" aria-hidden="true">↗</span>
                                        <span class="min-w-0 flex-1 truncate">{{ $externalSource['path'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </details>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Attached files</h3>
                @if ($attachments->isNotEmpty())
                    <a href="{{ route('library.index', ['search' => $conversation->title]) }}" class="text-[11px] text-zinc-500 hover:text-zinc-900">Library →</a>
                @endif
            </div>
            @if ($attachments->isEmpty())
                <p class="mt-2 text-xs text-zinc-400">No files attached.</p>
            @else
                <div class="mt-2 space-y-2">
                    @foreach ($attachments as $attachment)
                        <div class="flex items-center gap-2 rounded-md border border-zinc-100 p-2">
                            @if ($isAvailableImage($attachment))
                                <a href="{{ route('library.preview', $attachment) }}" target="_blank" rel="noopener" class="h-10 w-10 shrink-0 overflow-hidden rounded bg-zinc-100">
                                    <img src="{{ route('library.preview', $attachment) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                                </a>
                            @else
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-zinc-100 text-[9px] font-bold text-zinc-600">{{ $attachment->extension_label }}</div>
                            @endif
                            <div class="min-w-0 flex-1">
                                @if ($attachment->message_id)
                                    <a href="#message-{{ $attachment->message_id }}" class="block truncate text-xs font-medium hover:underline" title="{{ $attachment->filename }}">{{ $attachment->filename ?: 'Unnamed attachment' }}</a>
                                @else
                                    <span class="block truncate text-xs font-medium" title="{{ $attachment->filename }}">{{ $attachment->filename ?: 'Unnamed attachment' }}</span>
                                @endif
                                <span class="block truncate text-[10px] text-zinc-500">{{ $attachment->human_size }} · {{ $attachment->category->label() }}</span>
                            </div>
                            @if ($attachment->is_available)
                                <a href="{{ route('library.download', $attachment) }}" class="shrink-0 text-zinc-400 hover:text-zinc-900" aria-label="Download {{ $attachment->filename }}">↓</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</aside>
