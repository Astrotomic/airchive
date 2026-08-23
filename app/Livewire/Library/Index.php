<?php

namespace App\Livewire\Library;

use App\Enums\AttachmentType;
use App\Models\Attachment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Library'])]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $platform = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedPlatform(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'platform', 'sort']);
        $this->sort = 'newest';
        $this->resetPage();
    }

    public function render(): View
    {
        $base = Attachment::query()->where('user_id', Auth::id());
        $query = (clone $base)->with([
            'conversation:id,user_id,title,source_platform',
            'message:id,conversation_id,role,created_at',
        ]);

        if ($this->search !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function (Builder $query) use ($term): void {
                $query
                    ->whereLike('filename', $term, caseSensitive: false)
                    ->orWhereLike('mime_type', $term, caseSensitive: false)
                    ->orWhereLike('source_attachment_id', $term, caseSensitive: false)
                    ->orWhereHas('conversation', fn (Builder $conversation) => $conversation
                        ->whereLike('title', $term, caseSensitive: false));
            });
        }

        if ($this->platform !== '') {
            $query->where('source_platform', $this->platform);
        }

        $this->applyTypeFilter($query);
        $this->applySort($query);
        $attachments = $query->paginate(24);

        return view('livewire.library.index', [
            'attachments' => $attachments,
            'attachmentCards' => $attachments->getCollection()->map(function (Attachment $attachment): array {
                return [
                    'attachment' => $attachment,
                    'category' => $attachment->category,
                    'previewUrl' => route('library.preview', $attachment),
                    'chatUrl' => $attachment->conversation
                        ? route('conversations.show', $attachment->conversation).($attachment->message_id ? '#message-'.$attachment->message_id : '')
                        : null,
                    'textPreview' => $attachment->textPreview(),
                ];
            }),
            'platforms' => (clone $base)
                ->whereNotNull('source_platform')
                ->distinct()
                ->orderBy('source_platform')
                ->pluck('source_platform'),
            'stats' => [
                'all' => (clone $base)->count(),
                'available' => (clone $base)->where(function (Builder $query): void {
                    $query->whereNotNull('storage_path')->orWhereNotNull('external_url');
                })->count(),
                'images' => (clone $base)->where(function (Builder $query): void {
                    $query->where('attachment_type', AttachmentType::Image->value)->orWhereLike('mime_type', 'image/%');
                })->count(),
                'linked' => (clone $base)->whereNotNull('conversation_id')->count(),
            ],
        ]);
    }

    private function applyTypeFilter(Builder $query): void
    {
        match ($this->type) {
            'image' => $query->where(fn (Builder $query) => $query
                ->where('attachment_type', AttachmentType::Image->value)
                ->orWhereLike('mime_type', 'image/%')),
            'pdf' => $query->where(fn (Builder $query) => $query
                ->where('mime_type', 'application/pdf')
                ->orWhereLike('filename', '%.pdf', caseSensitive: false)),
            'text' => $query->where(fn (Builder $query) => $query
                ->whereLike('mime_type', 'text/%')
                ->orWhereIn('mime_type', ['application/json', 'application/ld+json', 'application/xml', 'application/x-yaml'])
                ->orWhereLike('filename', '%.md', caseSensitive: false)
                ->orWhereLike('filename', '%.json', caseSensitive: false)
                ->orWhereLike('filename', '%.jsonl', caseSensitive: false)),
            'document' => $query->where(fn (Builder $query) => $query
                ->whereLike('mime_type', '%word%')
                ->orWhereLike('mime_type', '%spreadsheet%')
                ->orWhereLike('mime_type', '%presentation%')
                ->orWhereLike('mime_type', '%opendocument%')
                ->orWhereLike('filename', '%.docx', caseSensitive: false)
                ->orWhereLike('filename', '%.xlsx', caseSensitive: false)
                ->orWhereLike('filename', '%.pptx', caseSensitive: false)),
            'audio' => $query->whereLike('mime_type', 'audio/%'),
            'video' => $query->whereLike('mime_type', 'video/%'),
            'archive' => $query->where(fn (Builder $query) => $query
                ->whereIn('mime_type', ['application/zip', 'application/x-tar', 'application/gzip', 'application/x-7z-compressed', 'application/vnd.rar'])
                ->orWhereLike('filename', '%.zip', caseSensitive: false)),
            'artifact' => $query->whereIn('attachment_type', [
                AttachmentType::Canvas->value,
                AttachmentType::AgentTool->value,
                AttachmentType::Terminal->value,
            ]),
            'unavailable' => $query->whereNull('storage_path')->whereNull('external_url'),
            default => null,
        };
    }

    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'name' => $query->orderBy('filename')->orderByDesc('created_at'),
            'largest' => $query->orderByDesc('byte_size')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}
