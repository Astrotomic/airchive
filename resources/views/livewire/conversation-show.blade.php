@use ('App\Enums\ExportFormat')
@use ('App\Enums\MessageRole')

<div class="grid max-w-full min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="min-w-0">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <a
                    href="{{ route('conversations.index') }}"
                    class="text-sm text-zinc-500 hover:text-zinc-800"
                    >← Back</a
                >
                <h1 class="mt-2 text-2xl font-semibold break-words">{{ $conversation->title ?: 'Untitled conversation' }}</h1>
                <p class="mt-1 text-sm text-zinc-500">{{ $conversation->source_platform->label() }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        wire:model.live="collapseTools"
                        class="rounded border-zinc-300"
                    />
                    Collapse tools
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        wire:model.live="showBranches"
                        class="rounded border-zinc-300"
                    />
                    Show branches
                </label>
                @foreach (ExportFormat::cases() as $format)
                    <a
                        href="{{ route('conversations.export', [$conversation, 'format' => $format->value]) }}"
                        class="rounded-md border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50"
                        >{{ $format->label() }}</a
                    >
                @endforeach
            </div>
        </div>

        <div class="space-y-3">
            @forelse ($turns as $turn)
                <details
                    wire:key="{{ $turn['id'] }}"
                    open
                    @class ([
                    'turn-card',
                    'turn-card--user' => $turn['role'] === MessageRole::User,
                    'turn-card--assistant' => $turn['role'] === MessageRole::Assistant,
                    'turn-card--system' => $turn['role'] === MessageRole::System,
                    'turn-card--tool' => $turn['role'] === MessageRole::Tool,
                    'turn-card--other' => ! $turn['role']->hasDedicatedStyle(),
                    'turn-card--branch' => ! $turn['is_on_canonical_path'],
                ])
                >
                    <summary class="turn-card__summary">
                        <span @class ([
                        'turn-card__badge',
                        'turn-card__badge--user' => $turn['role'] === MessageRole::User,
                        'turn-card__badge--assistant' => $turn['role'] === MessageRole::Assistant,
                        'turn-card__badge--system' => $turn['role'] === MessageRole::System,
                        'turn-card__badge--tool' => $turn['role'] === MessageRole::Tool,
                        'turn-card__badge--other' => ! $turn['role']->hasDedicatedStyle(),
                    ])>{{ $turn['role_label'] }}</span>
                        @if ($turn['started_at'])
                            <time class="turn-card__time">{{ $turn['started_at']->toDayDateTimeString() }}</time>
                        @endif
                        @if (! $turn['is_on_canonical_path'])
                            <span class="turn-card__branch-label">branch</span>
                        @endif
                    </summary>

                    <div class="turn-card__body space-y-3 text-sm leading-relaxed">
                        @foreach ($turn['message_ids'] as $messageId)
                            <span
                                id="message-{{ $messageId }}"
                                class="block scroll-mt-6"
                                aria-hidden="true"
                            ></span>
                        @endforeach
                        @foreach ($turn['blocks'] as $block)
                            <x-content-block
                                :block="$block"
                                :collapse-tools="$collapseTools"
                            />
                        @endforeach
                        @if ($turn['attachments']->isNotEmpty())
                            <div class="grid gap-3">
                                @foreach ($turn['attachments'] as $attachment)
                                    <x-attachment-preview :attachment="$attachment" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                </details>
            @empty
                <div class="rounded-lg border border-dashed border-zinc-300 bg-white p-8 text-center text-zinc-500">No messages in this view.</div>
            @endforelse
        </div>
    </div>

    <x-conversation-metadata-sidebar
        :conversation="$conversation"
        :stats="$conversationStats"
        :models="$conversationModels"
        :attachments="$conversationAttachments"
        :urls="$conversationUrls"
        :external-sources="$conversationExternalSources"
        :projects="$conversationProjects"
        :project-assignment-options="$projectAssignmentOptions"
    />
</div>
