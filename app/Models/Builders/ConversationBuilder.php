<?php

namespace App\Models\Builders;

use App\Enums\SourcePlatform;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/** @extends Builder<Conversation> */
final class ConversationBuilder extends Builder
{
    public function search(string $search): self
    {
        $search = trim($search);

        if (blank($search)) {
            return $this;
        }

        return $this->where(function (Builder $query) use ($search): void {
            $title = $query->getModel()->qualifyColumn('title');

            if ($query->getModel()->getConnection()->getDriverName() === 'sqlite') {
                $query
                    ->where($title, 'like', '%'.$search.'%')
                    ->orWhereRelation('messages.contentBlocks', 'text_content', 'like', '%'.$search.'%');

                return;
            }

            $query
                ->whereFullText($title, $search)
                ->orWhereHas('messages.contentBlocks', fn (Builder $query) => $query
                    ->whereFullText('text_content', $search));
        });
    }

    public function wherePlatform(?string $platform): self
    {
        if (blank($platform)) {
            return $this;
        }

        if (SourcePlatform::tryFrom($platform) === null) {
            return $this->whereRaw('1 = 0');
        }

        return $this->where('source_platform', $platform);
    }

    public function whereProject(int|string|null $project): self
    {
        if (blank($project)) {
            return $this;
        }

        if ($project === 'none') {
            return $this->whereDoesntHave('projects');
        }

        if (ctype_digit($project)) {
            return $this->whereHas('projects', fn (Builder $query) => $query->whereKey((int) $project));
        }

        return $this->whereRaw('1 = 0');
    }

    public function latestByMessage(): self
    {
        return $this
            ->orderByRaw('CASE WHEN conversations.last_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc($this->getModel()->qualifyColumn('last_message_at'))
            ->orderByDesc($this->getModel()->qualifyColumn('id'));
    }
}
