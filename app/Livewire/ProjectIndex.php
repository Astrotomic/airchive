<?php

namespace App\Livewire;

use App\Actions\Projects\MergeProjectsIntoProject;
use App\Enums\SourcePlatform;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Projects'])]
class ProjectIndex extends Component
{
    use WithPagination;

    public string $search = '';

    /** @var array<int, int|string> */
    public array $selectedProjectIds = [];

    public ?int $mergeTargetId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedProjectIds(): void
    {
        $this->selectedProjectIds = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->selectedProjectIds,
        )));

        if ($this->mergeTargetId === null || ! in_array($this->mergeTargetId, $this->selectedProjectIds, true)) {
            $this->mergeTargetId = $this->selectedProjectIds[0] ?? null;
        }
    }

    /** @param array<int, int|string> $projectIds */
    public function selectVisible(array $projectIds): void
    {
        $this->selectedProjectIds = array_values(array_unique([
            ...$this->selectedProjectIds,
            ...array_map(static fn (mixed $id): int => (int) $id, $projectIds),
        ]));
        $this->updatedSelectedProjectIds();
    }

    public function clearSelection(): void
    {
        $this->selectedProjectIds = [];
        $this->mergeTargetId = null;
        $this->resetValidation();
    }

    public function mergeSelected(): void
    {
        $validated = $this->validate([
            'selectedProjectIds' => ['required', 'array', 'min:2'],
            'selectedProjectIds.*' => ['required', 'integer', 'distinct'],
            'mergeTargetId' => ['required', 'integer'],
        ], [
            'selectedProjectIds.min' => 'Select at least two projects to merge.',
            'mergeTargetId.required' => 'Choose which project should be kept.',
        ]);
        $selectedIds = array_map('intval', $validated['selectedProjectIds']);
        $projects = Project::query()->whereIn('id', $selectedIds)->get();

        if ($projects->count() !== count($selectedIds)) {
            throw ValidationException::withMessages([
                'selectedProjectIds' => 'One or more selected projects are no longer available.',
            ]);
        }

        $target = $projects->firstWhere('id', (int) $validated['mergeTargetId']);

        if ($target === null) {
            throw ValidationException::withMessages([
                'mergeTargetId' => 'The project to keep must be part of the selection.',
            ]);
        }

        $this->authorize('update', $target);

        foreach ($projects as $project) {
            if (! $project->is($target)) {
                $this->authorize('delete', $project);
            }
        }

        $target = MergeProjectsIntoProject::make()->execute($projects, $target);

        Session::flash(
            'status',
            count($selectedIds).' projects merged. Future imports matching any of their source identifiers will be added here.',
        );
        $this->redirect(route('projects.show', $target), navigate: true);
    }

    public function render(): View
    {
        $projects = Project::query()
            ->withCount([
                'conversations',
                'sourceIdentifiers as chatgpt_identifier_count' => fn ($query) => $query
                    ->where('source_platform', SourcePlatform::ChatGpt->value),
                'sourceIdentifiers as cursor_identifier_count' => fn ($query) => $query
                    ->where('source_platform', SourcePlatform::Cursor->value),
                'sourceIdentifiers as codex_identifier_count' => fn ($query) => $query
                    ->where('source_platform', SourcePlatform::Codex->value),
            ])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhereHas('sourceIdentifiers', fn ($query) => $query
                            ->where('source_identifier', 'like', '%'.$this->search.'%'));
                });
            })
            ->orderBy('name')
            ->paginate(24);

        return view('livewire.project-index', [
            'projects' => $projects,
            'projectPlatformIdentifierCounts' => $projects->getCollection()->mapWithKeys(
                fn (Project $project): array => [
                    $project->id => collect(SourcePlatform::cases())
                        ->map(fn (SourcePlatform $platform): array => [
                            'label' => $platform === SourcePlatform::ChatGpt
                                ? 'ChatGPT'
                                : ucfirst($platform->value),
                            'count' => (int) $project->getAttribute($platform->value.'_identifier_count'),
                        ])
                        ->filter(fn (array $source): bool => $source['count'] > 0)
                        ->values(),
                ],
            ),
            'selectedProjects' => Project::query()
                ->whereIn('id', $this->selectedProjectIds)
                ->orderBy('name')
                ->get(),
        ]);
    }
}
