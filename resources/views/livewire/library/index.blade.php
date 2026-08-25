@use ('Illuminate\Support\Str')
@use ('App\Enums\AttachmentCategory')

<div>
    <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Library</h1>
            <p class="mt-1 text-sm text-zinc-500">Every file and image from your imported conversations and ChatGPT Library.</p>
        </div>
        <div class="grid grid-cols-4 divide-x divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 bg-white text-center text-xs">
            <div class="px-3 py-2"><strong class="block text-base text-zinc-900">{{ number_format($stats['all']) }}</strong><span class="text-zinc-500">files</span></div>
            <div class="px-3 py-2"><strong class="block text-base text-zinc-900">{{ number_format($stats['images']) }}</strong><span class="text-zinc-500">images</span></div>
            <div class="px-3 py-2"><strong class="block text-base text-zinc-900">{{ number_format($stats['linked']) }}</strong><span class="text-zinc-500">in chats</span></div>
            <div class="px-3 py-2"><strong class="block text-base text-zinc-900">{{ number_format($stats['available']) }}</strong><span class="text-zinc-500">available</span></div>
        </div>
    </div>

    <div class="mb-6 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(18rem,1fr)_auto_auto_auto_auto]">
            <label class="relative block">
                <span class="sr-only">Search files</span>
                <svg class="pointer-events-none absolute top-2.5 left-3 h-5 w-5 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                </svg>
                <input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="Search filename, MIME type, file ID or chat…"
                    class="w-full rounded-md border border-zinc-300 py-2 pr-3 pl-10 text-sm"
                />
            </label>
            <select
                wire:model.live="type"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
            >
                <option value="">All types</option>
                <option value="image">Images</option>
                <option value="pdf">PDFs</option>
                <option value="text">Text & code</option>
                <option value="document">Documents</option>
                <option value="audio">Audio</option>
                <option value="video">Video</option>
                <option value="archive">Archives</option>
                <option value="artifact">AI artifacts & Canvas</option>
                <option value="unavailable">Missing content</option>
            </select>
            <select
                wire:model.live="platform"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
            >
                <option value="">All sources</option>
                @foreach ($platforms as $sourcePlatform)
                    <option value="{{ $sourcePlatform->value }}">{{ $sourcePlatform->label() }}</option>
                @endforeach
            </select>
            <select
                wire:model.live="sort"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm"
            >
                <option value="newest">Newest import</option>
                <option value="oldest">Oldest import</option>
                <option value="name">Filename</option>
                <option value="largest">Largest first</option>
            </select>
            @if ($search !== '' || $type !== '' || $platform !== '' || $sort !== 'newest')
                <button
                    wire:click="clearFilters"
                    class="rounded-md px-3 py-2 text-sm text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900"
                >
                    Clear
                </button>
            @endif
        </div>
    </div>

    @if ($attachments->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 bg-white p-12 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-500">
                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <path d="M14 2v6h6" />
                </svg>
            </div>
            <h2 class="font-medium">No matching files</h2>
            <p class="mt-1 text-sm text-zinc-500">Try changing the filters or import an archive with attachments.</p>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($attachmentCards as ['attachment' => $attachment, 'category' => $category, 'previewUrl' => $previewUrl, 'chatUrl' => $chatUrl, 'textPreview' => $textPreview])
                <article
                    wire:key="attachment-{{ $attachment->id }}"
                    class="group flex min-w-0 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-md"
                >
                    <div class="relative flex aspect-[16/10] items-center justify-center overflow-hidden border-b border-zinc-100 bg-zinc-100">
                        @if (! $attachment->is_available)
                            <div class="text-center text-zinc-400">
                                <svg class="mx-auto h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <path d="M14 2v6h6M9 15l6-6M9 9l6 6" />
                                </svg>
                                <span class="mt-2 block text-xs font-medium">Content not in export</span>
                            </div>
                        @elseif ($category === AttachmentCategory::Image)
                            <img
                                src="{{ $previewUrl }}"
                                alt=""
                                loading="lazy"
                                class="h-full w-full object-contain"
                            />
                        @elseif ($category === AttachmentCategory::Video)
                            <video
                                controls
                                preload="metadata"
                                class="h-full w-full bg-black"
                            >
                                <source
                                    src="{{ $previewUrl }}"
                                    type="{{ $attachment->mime_type }}"
                                />
                            </video>
                        @elseif ($category === AttachmentCategory::Audio)
                            <div class="w-full px-5 text-center">
                                <svg class="mx-auto mb-4 h-10 w-10 text-zinc-500" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M9 18V5l12-2v13" />
                                    <circle cx="6" cy="18" r="3" />
                                    <circle cx="18" cy="16" r="3" />
                                </svg>
                                <audio
                                    controls
                                    preload="metadata"
                                    class="w-full"
                                >
                                    <source
                                        src="{{ $previewUrl }}"
                                        type="{{ $attachment->mime_type }}"
                                    />
                                </audio>
                            </div>
                        @elseif ($textPreview !== null)
                            <pre class="h-full w-full overflow-hidden p-4 text-xs leading-relaxed break-words whitespace-pre-wrap text-zinc-600"><code>{{ $textPreview }}</code></pre>
                            <div class="pointer-events-none absolute inset-x-0 bottom-0 h-12 bg-gradient-to-t from-zinc-100 to-transparent"></div>
                        @else
                            <div class="text-center">
                                <div class="mx-auto flex h-16 w-14 items-center justify-center rounded-lg border border-zinc-300 bg-white text-xs font-bold text-zinc-600 shadow-sm">{{ $attachment->extension_label }}</div>
                                <span class="mt-3 block text-xs font-medium text-zinc-500">{{ $category->label() }}</span>
                            </div>
                        @endif
                        <span class="absolute top-3 left-3 rounded-full border border-white/70 bg-white/90 px-2 py-1 text-[10px] font-semibold tracking-wide text-zinc-700 uppercase shadow-sm backdrop-blur">{{ $category->label() }}</span>
                    </div>

                    <div class="flex flex-1 flex-col p-4">
                        <h2
                            class="truncate font-medium"
                            title="{{ $attachment->filename }}"
                        >
                            {{ $attachment->filename ?: 'Unnamed attachment' }}
                        </h2>
                        <p
                            class="mt-1 truncate text-xs text-zinc-500"
                            title="{{ $attachment->mime_type }}"
                        >{{ $attachment->human_size }} · {{ $attachment->mime_type ?: 'Unknown MIME type' }}</p>

                        @if ($attachment->conversation)
                            <a
                                href="{{ $chatUrl }}"
                                class="mt-3 flex items-center gap-2 rounded-md bg-zinc-50 px-2.5 py-2 text-xs text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900"
                            >
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" /></svg>
                                <span class="truncate">{{ $attachment->conversation->title ?: 'Untitled conversation' }}</span>
                            </a>
                        @else
                            <div class="mt-3 flex items-center gap-2 rounded-md bg-violet-50 px-2.5 py-2 text-xs text-violet-700">
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                                    <path d="M3 7h5l2 2h11v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                                    <path d="M3 7V5a2 2 0 0 1 2-2h3l2 2h4" />
                                </svg>
                                <span>ChatGPT Library / unlinked</span>
                            </div>
                        @endif

                        <details class="mt-3 text-xs text-zinc-500">
                            <summary class="cursor-pointer select-none hover:text-zinc-800">Metadata</summary>
                            <dl class="mt-2 space-y-1.5 rounded-md border border-zinc-100 bg-zinc-50 p-2.5">
                                <div class="flex justify-between gap-3">
                                    <dt>Source</dt>
                                    <dd class="truncate text-right text-zinc-700">{{ $attachment->source_platform?->label() ?? 'Unknown' }}</dd>
                                </div>
                                <div class="flex justify-between gap-3">
                                    <dt>Imported</dt>
                                    <dd class="text-right text-zinc-700">{{ $attachment->created_at?->toDayDateTimeString() ?: '—' }}</dd>
                                </div>
                                @if ($attachment->message)
                                    <div class="flex justify-between gap-3">
                                        <dt>Message</dt>
                                        <dd class="text-right text-zinc-700">{{ $attachment->message->role->label() }}{{ $attachment->message->created_at ? ' · '.$attachment->message->created_at->toDateString() : '' }}</dd>
                                    </div>
                                @endif
                                @if ($attachment->source_attachment_id)
                                    <div>
                                        <dt>Source ID</dt>
                                        <dd class="mt-0.5 font-mono text-[10px] break-all text-zinc-700">{{ $attachment->source_attachment_id }}</dd>
                                    </div>
                                @endif
                                @if ($attachment->checksum)
                                    <div>
                                        <dt>SHA-256</dt>
                                        <dd class="mt-0.5 font-mono text-[10px] break-all text-zinc-700">{{ $attachment->checksum }}</dd>
                                    </div>
                                @endif
                                @if ($attachment->source_ref)
                                    <div>
                                        <dt>Source metadata</dt>
                                        <dd class="mt-1 max-h-36 overflow-auto rounded bg-white p-2">
                                            <pre class="font-mono text-[10px] leading-relaxed break-all whitespace-pre-wrap text-zinc-700">{{ Str::limit(json_encode($attachment->source_ref, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 4000) }}</pre>
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </details>

                        <div class="mt-auto flex items-center gap-2 pt-4">
                            @if ($attachment->is_available)
                                <a
                                    href="{{ $previewUrl }}"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex-1 rounded-md border border-zinc-300 px-3 py-2 text-center text-xs font-medium hover:bg-zinc-50"
                                    >Open</a
                                >
                                <a
                                    href="{{ route('library.download', $attachment) }}"
                                    class="rounded-md bg-zinc-900 px-3 py-2 text-xs font-medium text-white hover:bg-zinc-700"
                                    aria-label="Download {{ $attachment->filename }}"
                                    >Download</a
                                >
                            @else
                                <span class="w-full rounded-md bg-zinc-100 px-3 py-2 text-center text-xs text-zinc-400">File bytes unavailable</span>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-8">{{ $attachments->links() }}</div>
    @endif
</div>
