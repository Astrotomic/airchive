<?php

namespace App\Console\Commands;

use App\Actions\Projects\AssignConversationToProjects;
use App\Actions\Projects\ExtractProjectIdentifiers;
use App\Enums\SourcePlatform;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BackfillProjectsCommand extends Command
{
    protected $signature = 'archive:projects-backfill
        {--user= : Restrict the backfill to a user ID or email address}';

    protected $description = 'Create project groups from identifiers stored on existing conversations';

    public function handle(): int
    {
        $user = $this->resolveUser($this->option('user'));

        if ($this->option('user') !== null && $user === null) {
            return self::FAILURE;
        }

        $query = Conversation::query()
            ->withoutGlobalScopes()
            ->when($user !== null, fn (Builder $query) => $query->where('user_id', $user->id));
        $total = (clone $query)->count();
        $processed = 0;
        $assignments = 0;

        $this->components->info('Scanning '.number_format($total).' existing conversations for project identifiers.');

        $query->chunkById(100, function ($conversations) use (&$processed, &$assignments): void {
            foreach ($conversations as $conversation) {
                $data = match ($conversation->source_platform) {
                    SourcePlatform::ChatGpt => $conversation->metadata ?? [],
                    SourcePlatform::Cursor => $conversation->metadata['cursor_workspace'] ?? null,
                    SourcePlatform::Codex => $this->codexMetadata($conversation),
                };
                $identifiers = ExtractProjectIdentifiers::make()->execute($conversation->source_platform, $data);

                $assignments += count(AssignConversationToProjects::make()->execute($conversation, $identifiers));
                $processed++;
            }
        });

        $this->components->info(
            'Backfill completed: '.number_format($processed).' conversations scanned, '.number_format($assignments).' project assignments found.',
        );

        return self::SUCCESS;
    }

    /** @return array<int|string, mixed> */
    private function codexMetadata(Conversation $conversation): array
    {
        $conversation->loadMissing([
            'messages:id,conversation_id,metadata',
            'messages.contentBlocks:id,message_id,structured_content',
        ]);

        return [
            $conversation->metadata,
            $conversation->messages->pluck('metadata')->all(),
            $conversation->messages
                ->flatMap(fn ($message) => $message->contentBlocks->pluck('structured_content'))
                ->all(),
        ];
    }

    private function resolveUser(mixed $identifier): ?User
    {
        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', Str::lower($identifier))->first();

        if ($user === null) {
            $this->error("No user found for '{$identifier}'.");
        }

        return $user;
    }
}
