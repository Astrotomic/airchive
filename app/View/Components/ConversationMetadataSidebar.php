<?php

namespace App\View\Components;

use App\Enums\AttachmentCategory;
use App\Models\Attachment;
use App\Models\Conversation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\Component;

final class ConversationMetadataSidebar extends Component
{
    public readonly int $externalSourceCount;

    /**
     * @param  array<string, mixed>  $stats
     * @param  Collection<int, string>  $models
     * @param  Collection<int, Attachment>  $attachments
     * @param  Collection<int, array{label: string, url: string}>  $urls
     * @param  Collection<int, array<string, mixed>>  $externalSources
     * @param  Collection<int, mixed>  $projects
     * @param  Collection<int, mixed>  $projectAssignmentOptions
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $stats,
        public readonly Collection $models,
        public readonly Collection $attachments,
        public readonly Collection $urls,
        public readonly Collection $externalSources,
        public readonly Collection $projects,
        public readonly Collection $projectAssignmentOptions,
    ) {
        $this->externalSourceCount = $externalSources->sum(
            fn (array $group): int => $group['links']->count(),
        );
    }

    public function sourceInitial(string $domain): string
    {
        return Str::substr($domain, 0, 1);
    }

    public function isAvailableImage(Attachment $attachment): bool
    {
        return $attachment->category === AttachmentCategory::Image && $attachment->is_available;
    }

    public function render(): View
    {
        return view('components.conversation-metadata-sidebar');
    }
}
