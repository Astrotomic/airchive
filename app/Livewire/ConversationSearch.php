<?php

namespace App\Livewire;

use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Search'])]
class ConversationSearch extends Component
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
        $results = Conversation::query()
            ->with('projects:id,name,emoji')
            ->search($this->query)
            ->wherePlatform($this->platform)
            ->whereProject($this->projectFilter)
            ->latestByMessage()
            ->paginate(20);

        return view('livewire.conversation-search', [
            'results' => $results,
            'platforms' => SourcePlatform::cases(),
            'projects' => Project::query()->orderBy('name')->get(),
        ]);
    }
}
