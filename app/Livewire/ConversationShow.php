<?php

namespace App\Livewire;

use App\Actions\Conversations\ExtractExternalSources;
use App\Actions\Projects\AttachConversationToProject;
use App\Enums\MessageRole;
use App\Enums\SourcePlatform;
use App\Managers\Favicons\FaviconManager;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\ValueObjects\ModelDisplayName;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app', ['title' => 'Conversation'])]
class ConversationShow extends Component
{
    public Conversation $conversation;

    public bool $showBranches = false;

    public bool $collapseTools = true;

    public ?int $projectToAssign = null;

    public function mount(Conversation $conversation): void
    {
        $this->authorize('view', $conversation);
        $this->conversation = $conversation;
    }

    public function assignProject(): void
    {
        $this->authorize('update', $this->conversation);
        $validated = $this->validate([
            'projectToAssign' => ['required', 'integer'],
        ], [
            'projectToAssign.required' => 'Choose a project first.',
        ]);
        $project = Project::query()->findOrFail((int) $validated['projectToAssign']);
        $this->authorize('update', $project);

        AttachConversationToProject::make()->execute($this->conversation, $project);
        $this->projectToAssign = null;

        Session::flash('status', 'Conversation added to '.$project->display_name.'.');
    }

    public function render(): View
    {
        $messages = Message::query()
            ->where('conversation_id', $this->conversation->id)
            ->with([
                'attachments',
                'contentBlocks' => fn ($query) => $query->orderBy('position'),
                'contentBlocks.attachments',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $viewMessages = $messages->reject(fn (Message $message): bool => $message->is_hidden);

        if (! $this->showBranches) {
            $viewMessages = $viewMessages->where('is_on_canonical_path', true);
        }

        $attachments = Attachment::query()
            ->where('conversation_id', $this->conversation->id)
            ->with('message:id,conversation_id,role,created_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $projects = $this->conversation->projects()
            ->orderBy('name')
            ->get();

        return view('livewire.conversation-show', [
            'turns' => $this->groupIntoTurns($viewMessages),
            'conversationStats' => $this->conversationStats($messages),
            'conversationModels' => $this->conversationModels($messages),
            'conversationUrls' => $this->conversationUrls(),
            'conversationExternalSources' => $this->externalSourceGroups($messages),
            'conversationAttachments' => $attachments,
            'conversationProjects' => $projects,
            'projectAssignmentOptions' => $projects->isEmpty()
                ? Project::query()->orderBy('name')->get()
                : collect(),
        ]);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return array<string, mixed>
     */
    private function conversationStats(Collection $messages): array
    {
        $dates = $messages->pluck('created_at')->filter()->sort()->values();
        $startedAt = $this->conversation->first_message_at ?? $dates->first();
        $endedAt = $this->conversation->last_message_at ?? $dates->last();

        return [
            'hidden_count' => $messages->where('is_hidden', true)->count(),
            'roles' => $messages->countBy(fn (Message $message): string => $message->role->value)->sortDesc(),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, string>
     */
    private function conversationModels(Collection $messages): Collection
    {
        $keys = [
            'model_slug',
            'default_model_slug',
            'requested_model_slug',
            'resolved_model_slug',
            'model',
            'model_name',
        ];
        $models = collect([$this->conversation->metadata['default_model_slug'] ?? null]);

        foreach ($messages as $message) {
            foreach ($keys as $key) {
                $models->push($message->metadata[$key] ?? null);
            }
        }

        return $models
            ->filter(fn (mixed $model): bool => is_string($model) && trim($model) !== '')
            ->map(fn (string $model): string => new ModelDisplayName($model))
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, array{domain: string, favicon_url: string, links: Collection<int, array{url: string, label: string, host: string, group_host: string, path: string}>}>
     */
    private function externalSourceGroups(Collection $messages): Collection
    {
        $favicons = app(FaviconManager::class);

        return ExtractExternalSources::make()
            ->execute($messages)
            ->groupBy('group_host')
            ->map(function (Collection $links, string $domain) use ($favicons): array {
                return [
                    'domain' => $domain,
                    'favicon_url' => $favicons->url($domain),
                    'links' => $links
                        ->sort(fn (array $left, array $right): int => strnatcasecmp($left['path'], $right['path']) ?: strnatcasecmp($left['url'], $right['url']))
                        ->values(),
                ];
            })
            ->sort(fn (array $left, array $right): int => strnatcasecmp($left['domain'], $right['domain']))
            ->values();
    }

    /** @return Collection<int, array{label: string, url: string}> */
    private function conversationUrls(): Collection
    {
        $urls = collect();
        $metadata = $this->conversation->metadata ?? [];

        if ($this->conversation->source_platform === SourcePlatform::ChatGpt) {
            $urls->push([
                'label' => 'Open in ChatGPT',
                'url' => 'https://chatgpt.com/c/'.$this->conversation->source_conversation_id,
            ]);
        }

        foreach (($metadata['shared_conversations'] ?? []) as $sharedConversation) {
            $url = is_array($sharedConversation) ? ($sharedConversation['source_url'] ?? null) : null;

            if (is_string($url) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $urls->push(['label' => 'Shared conversation', 'url' => $url]);
            }
        }

        foreach (['source_url', 'url'] as $key) {
            $url = $metadata[$key] ?? null;

            if (is_string($url) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $urls->push(['label' => 'Source URL', 'url' => $url]);
            }
        }

        return $urls->unique('url')->values();
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, array{role: MessageRole, started_at: mixed, ended_at: mixed, blocks: Collection<int, ContentBlock>, attachments: Collection<int, Attachment>, message_ids: list<int>, is_on_canonical_path: bool}>
     */
    private function groupIntoTurns(Collection $messages): Collection
    {
        /** @var Collection<int, array{role: MessageRole, started_at: mixed, ended_at: mixed, blocks: Collection<int, ContentBlock>, attachments: Collection<int, Attachment>, message_ids: list<int>, is_on_canonical_path: bool}> $turns */
        $turns = $messages->reduce(function (Collection $turns, Message $message): Collection {
            $blocks = $message->contentBlocks->filter(
                fn ($block): bool => filled($block->text_content)
                    || filled($block->structured_content)
                    || $block->attachments->isNotEmpty(),
            );
            $attachments = $message->attachments->whereNull('content_block_id')->values();

            if ($blocks->isEmpty() && $attachments->isEmpty()) {
                return $turns;
            }

            $last = $turns->last();

            if (is_array($last) && $last['role'] === $message->role) {
                $last['blocks'] = $last['blocks']->concat($blocks);
                $last['attachments'] = $last['attachments']->concat($attachments);
                $last['message_ids'][] = $message->id;
                $last['ended_at'] = $message->created_at ?? $last['ended_at'];

                return $turns->slice(0, -1)->push($last);
            }

            return $turns->push([
                'id' => 'turn-'.$turns->count(),
                'role' => $message->role,
                'role_label' => $message->role->label(),
                'started_at' => $message->created_at,
                'ended_at' => $message->created_at,
                'blocks' => $blocks,
                'attachments' => $attachments,
                'message_ids' => [$message->id],
                'is_on_canonical_path' => $message->is_on_canonical_path,
            ]);
        }, collect());

        return $turns;
    }
}
