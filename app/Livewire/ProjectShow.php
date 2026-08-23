<?php

namespace App\Livewire;

use App\Actions\Projects\MergeProjectIntoProject;
use App\Enums\SourcePlatform;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Project'])]
class ProjectShow extends Component
{
    use WithPagination;

    public Project $project;

    public string $name = '';

    public string $emoji = '';

    #[Url(except: '')]
    public string $platform = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $mergeProjectId = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
        $this->name = $project->name;
        $this->emoji = $project->emoji ?? '';
    }

    public function rename(): void
    {
        $this->authorize('update', $this->project);
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'emoji' => ['nullable', 'string', 'max:32'],
        ]);

        $emoji = trim($validated['emoji'] ?? '');
        $this->project->update([
            'name' => trim($validated['name']),
            'emoji' => $emoji !== '' ? $emoji : null,
        ]);
        $this->project->refresh();
        $this->name = $this->project->name;
        $this->emoji = $this->project->emoji ?? '';

        Session::flash('status', 'Project updated.');
    }

    public function updatedPlatform(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function merge(): void
    {
        $this->authorize('update', $this->project);
        $validated = $this->validate([
            'mergeProjectId' => ['required', 'integer'],
        ]);
        $source = Project::query()
            ->whereKey($validated['mergeProjectId'])
            ->whereKeyNot($this->project->id)
            ->firstOrFail();
        $this->authorize('delete', $source);

        $this->project = MergeProjectIntoProject::make()->execute($source, $this->project);
        $this->mergeProjectId = null;
        $this->resetPage();

        Session::flash('status', 'Projects merged. Future imports matching either source will be added here.');
    }

    public function render(): View
    {
        $platformCounts = $this->project->conversations()
            ->selectRaw('conversations.source_platform, COUNT(*) as aggregate')
            ->groupBy('conversations.source_platform')
            ->pluck('aggregate', 'conversations.source_platform');

        return view('livewire.project-show', [
            'identifiers' => $this->project->sourceIdentifiers()
                ->orderBy('source_platform')
                ->orderBy('identifier_type')
                ->orderBy('source_identifier')
                ->get(),
            'conversations' => $this->project->conversations()
                ->with('projects:id,name,emoji')
                ->forPlatform($this->platform)
                ->search($this->search)
                ->latestByMessage()
                ->paginate(20),
            'platforms' => collect(SourcePlatform::cases())
                ->filter(fn (SourcePlatform $platform): bool => $platformCounts->has($platform->value))
                ->values(),
            'platformCounts' => $platformCounts,
            'projectConversationCount' => $platformCounts->sum(),
            'mergeCandidates' => Project::query()
                ->whereKeyNot($this->project->id)
                ->withCount('conversations')
                ->orderBy('name')
                ->get(),
        ]);
    }
}
