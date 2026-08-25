<figure class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    @if (! $attachment->is_available)
        <div class="flex min-h-32 items-center justify-center bg-zinc-100 p-6 text-center text-sm text-zinc-500">
            <div>
                <svg class="mx-auto mb-2 h-8 w-8 text-zinc-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <path d="M14 2v6h6M9 15l6-6M9 9l6 6" />
                </svg>
                <span>File content was not included in the export.</span>
            </div>
        </div>
    @elseif ($isImage)
        <a
            href="{{ $previewUrl }}"
            target="_blank"
            rel="noopener"
            class="block bg-zinc-100"
        >
            <img
                src="{{ $previewUrl }}"
                alt="{{ $attachment->filename ?: 'Conversation image' }}"
                loading="lazy"
                class="max-h-[42rem] w-full object-contain"
            />
        </a>
    @elseif ($isVideo)
        <video
            controls
            preload="metadata"
            class="max-h-[42rem] w-full bg-black"
        >
            <source
                src="{{ $previewUrl }}"
                type="{{ $attachment->mime_type }}"
            />
        </video>
    @elseif ($isAudio)
        <div class="bg-zinc-50 p-4">
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
    @else
        <div class="flex items-center gap-3 bg-zinc-50 p-4">
            <div class="flex h-12 w-10 shrink-0 items-center justify-center rounded border border-zinc-300 bg-white text-[10px] font-bold text-zinc-600">{{ $attachment->extension_label }}</div>
            <div class="min-w-0">
                <p class="truncate text-sm font-medium">{{ $attachment->filename ?: 'Unnamed attachment' }}</p>
                <p class="mt-0.5 text-xs text-zinc-500">{{ $attachment->human_size }} · {{ $attachment->mime_type ?: $category->label() }}</p>
            </div>
        </div>
    @endif

    <figcaption class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200 px-3 py-2 text-xs">
        <div class="min-w-0">
            <span class="block truncate font-medium text-zinc-700">{{ $attachment->filename ?: 'Unnamed attachment' }}</span>
            <span class="text-zinc-500">{{ $attachment->human_size }}{{ $attachment->mime_type ? ' · '.$attachment->mime_type : '' }}</span>
        </div>
        @if ($attachment->is_available)
            <div class="flex shrink-0 items-center gap-2">
                <a
                    href="{{ $previewUrl }}"
                    target="_blank"
                    rel="noopener"
                    class="rounded border border-zinc-300 px-2.5 py-1.5 font-medium text-zinc-700 hover:bg-zinc-50"
                    >Open</a
                >
                <a
                    href="{{ $downloadUrl }}"
                    class="rounded bg-zinc-900 px-2.5 py-1.5 font-medium text-white hover:bg-zinc-700"
                    >Download</a
                >
            </div>
        @endif
    </figcaption>
</figure>
