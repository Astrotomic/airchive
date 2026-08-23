<?php

namespace App\Livewire;

use App\Enums\SourcePlatform;
use App\Models\Conversation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app', ['title' => 'Conversations'])]
class ConversationIndex extends Component
{
    use WithPagination;

    public string $platform = '';

    public function updatedPlatform(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = Conversation::query()
            ->with('projects:id,name,emoji')
            ->forPlatform($this->platform)
            ->latestByMessage();

        return view('livewire.conversation-index', [
            'conversations' => $query->paginate(20),
            'platforms' => SourcePlatform::cases(),
        ]);
    }
}
