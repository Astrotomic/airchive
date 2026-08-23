<?php

namespace App\Console\Commands;

use App\Actions\Conversations\ExportConversations;
use App\Enums\ExportFormat;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class ExportChatsCommand extends Command
{
    protected $signature = 'archive:export
        {chat : Exact chat ID/UUID or a search term}
        {--user= : User ID or email address that owns the chats}
        {--chats-only : Export chat documents without files}
        {--files-only : Export only files directly attached to the selected chats}
        {--format=md : Chat format: md, html, or json}
        {--output= : Destination directory (defaults to storage/app/exports)}';

    protected $description = 'Export chats selected by exact ID/UUID or full-text search';

    public function handle(
        Filesystem $filesystem,
    ): int {
        if (! $this->validateOptions()) {
            return self::FAILURE;
        }

        $format = $this->normalizeFormat((string) $this->option('format'));

        if ($format === null) {
            return self::FAILURE;
        }

        $user = $this->resolveUser($this->option('user'));

        if ($user === null) {
            return self::FAILURE;
        }

        $identifier = trim((string) $this->argument('chat'));

        if ($identifier === '') {
            $this->components->error('The chat ID or search term must not be empty.');

            return self::FAILURE;
        }

        [$conversations, $exact] = $this->resolveConversations($user, $identifier);

        if ($conversations->isEmpty()) {
            $this->components->error("No chats found for '{$identifier}'.");

            return self::FAILURE;
        }

        if ($exact) {
            $conversation = $conversations->first();
            $this->components->info("Resolved exact chat #{$conversation->id}: ".($conversation->title ?: 'Untitled conversation'));
        } else {
            $this->components->info("Found {$conversations->count()} chats matching '{$identifier}'.");
            $this->table(
                ['ID', 'Source ID', 'Title'],
                $conversations->map(fn (Conversation $conversation): array => [
                    $conversation->id,
                    $conversation->source_conversation_id,
                    $conversation->title ?: 'Untitled conversation',
                ])->all(),
            );
        }

        $destination = $this->destination($this->option('output'));

        if ($filesystem->exists($destination) && ! $filesystem->isDirectory($destination)) {
            $this->components->error("The export destination is not a directory: {$destination}");

            return self::FAILURE;
        }

        $includeChats = ! (bool) $this->option('files-only');
        $includeFiles = ! (bool) $this->option('chats-only');
        $this->line('Owner: '.$user->email);
        $this->line('Destination: '.$destination);
        $this->line('Mode: '.$this->mode($includeChats, $includeFiles));

        try {
            $result = ExportConversations::make()->execute(
                $conversations,
                $format,
                $destination,
                $includeChats,
                $includeFiles,
            );
        } catch (Throwable $exception) {
            $this->components->error('Export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Export completed.');
        $this->line("Chats exported: {$result->chatCount}");
        $this->line("Files exported: {$result->fileCount}");

        if ($result->unavailableFiles !== []) {
            $this->components->warn(count($result->unavailableFiles).' attachment(s) had no available stored file:');

            foreach ($result->unavailableFiles as $file) {
                $this->line('  - '.$file);
            }
        }

        return self::SUCCESS;
    }

    private function validateOptions(): bool
    {
        if ($this->option('chats-only') && $this->option('files-only')) {
            $this->components->error('The --chats-only and --files-only options cannot be used together.');

            return false;
        }

        if ($this->option('files-only') && $this->input->hasParameterOption('--format')) {
            $this->components->error('The --format option cannot be used with --files-only.');

            return false;
        }

        return true;
    }

    private function normalizeFormat(string $format): ?ExportFormat
    {
        $format = ExportFormat::parse($format);

        if ($format === null) {
            $this->components->error('Unsupported format. Choose one of: md, html, json.');

            return null;
        }

        return $format;
    }

    private function resolveUser(mixed $identifier): ?User
    {
        if (is_string($identifier) && trim($identifier) !== '') {
            $identifier = trim($identifier);
            $user = ctype_digit($identifier)
                ? User::query()->find((int) $identifier)
                : User::query()->where('email', Str::lower($identifier))->first();

            if ($user === null) {
                $this->components->error("No user found for '{$identifier}'.");
            }

            return $user;
        }

        $users = User::query()->limit(2)->get();

        if ($users->count() === 1) {
            return $users->first();
        }

        if ($users->isEmpty()) {
            $this->components->error('No user exists. Create one first or pass --user after creating it.');
        } else {
            $this->components->error('Multiple users exist. Pass --user=<id-or-email> to select the owner.');
        }

        return null;
    }

    /**
     * @return array{0: Collection<int, Conversation>, 1: bool}
     */
    private function resolveConversations(
        User $user,
        string $identifier,
    ): array {
        $query = Conversation::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id);
        $conversation = ctype_digit($identifier)
            ? (clone $query)->whereKey((int) $identifier)->first()
            : null;

        $conversation ??= (clone $query)
            ->where('source_conversation_id', $identifier)
            ->first();

        if ($conversation !== null) {
            return [collect([$conversation]), true];
        }

        return [
            (clone $query)
                ->search($identifier)
                ->latestByMessage()
                ->get(),
            false,
        ];
    }

    private function destination(mixed $output): string
    {
        if (! is_string($output) || trim($output) === '') {
            return storage_path('app/exports/'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8)));
        }

        $output = trim($output);

        if (! str_starts_with($output, DIRECTORY_SEPARATOR)
            && preg_match('/^[A-Za-z]:[\\\\\/]/', $output) !== 1) {
            return base_path($output);
        }

        $trimmed = rtrim($output, '/\\');

        return $trimmed !== '' ? $trimmed : DIRECTORY_SEPARATOR;
    }

    private function mode(bool $includeChats, bool $includeFiles): string
    {
        return match (true) {
            $includeChats && $includeFiles => 'chats and directly attached files',
            $includeChats => 'chats only',
            default => 'directly attached files only',
        };
    }
}
