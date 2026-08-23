<?php

namespace App\Livewire\Exports;

use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Export'])]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $query = '';

    #[Url(except: '')]
    public string $platform = '';

    #[Url(as: 'project', except: '')]
    public string $projectFilter = '';

    public function updatedQuery(): void
    {
        $this->resetPage();
    }

    public function updatedPlatform(): void
    {
        $this->resetPage();
    }

    public function updatedProjectFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['query', 'platform', 'projectFilter']);
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.exports.index', [
            'conversations' => Conversation::query()
                ->with('projects:id,name,emoji')
                ->search($this->query)
                ->forPlatform($this->platform)
                ->forProject($this->projectFilter)
                ->latestByMessage()
                ->paginate(10),
            'platforms' => SourcePlatform::cases(),
            'projects' => Project::query()->orderBy('name')->get(),
        ]);
    }
}
