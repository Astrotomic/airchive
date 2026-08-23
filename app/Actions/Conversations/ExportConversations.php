<?php

namespace App\Actions\Conversations;

use App\Actions\Action;
use App\Enums\ExportFormat;
use App\Managers\Exports\ConversationExportManager;
use App\Models\Attachment;
use App\Models\Conversation;
use App\ValueObjects\ConversationExportResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class ExportConversations extends Action
{
    public function __construct(
        private ConversationExportManager $exports,
        private FilesystemManager $filesystems,
        private Filesystem $filesystem,
    ) {}

    /**
     * @param  Collection<int, Conversation>  $conversations
     */
    public function execute(
        Collection $conversations,
        ExportFormat $format,
        string $destination,
        bool $includeChats = true,
        bool $includeFiles = true,
    ): ConversationExportResult {
        $this->filesystem->ensureDirectoryExists($destination);
        $destinationDisk = $this->filesystems->build([
            'driver' => 'local',
            'root' => $destination,
            'throw' => true,
        ]);
        $chatCount = 0;

        if ($includeChats) {
            foreach ($conversations as $conversation) {
                $path = 'chats/'.$this->conversationFilename($conversation, $format);

                if (! $destinationDisk->put($path, $this->exports->export($conversation, $format))) {
                    throw new RuntimeException("Failed to write exported chat: {$path}");
                }

                $chatCount++;
            }
        }

        if (! $includeFiles) {
            return new ConversationExportResult($chatCount, 0);
        }

        $sourceDisk = $this->filesystems->disk();
        $conversationIds = $conversations->pluck('id');
        $conversationsById = $conversations->keyBy('id');
        $fileCount = 0;
        $unavailableFiles = [];

        Attachment::query()
            ->withoutGlobalScopes()
            ->whereIn('conversation_id', $conversationIds)
            ->orderBy('conversation_id')
            ->orderBy('id')
            ->each(function (Attachment $attachment) use (
                $sourceDisk,
                $destinationDisk,
                $conversationsById,
                &$fileCount,
                &$unavailableFiles,
            ): void {
                $label = $attachment->filename ?: "attachment-{$attachment->id}";

                if ($attachment->storage_path === null || ! $sourceDisk->exists($attachment->storage_path)) {
                    $unavailableFiles[] = $label;

                    return;
                }

                $conversation = $conversationsById->get($attachment->conversation_id);

                if (! $conversation instanceof Conversation) {
                    return;
                }

                $path = sprintf(
                    'files/%s/%d-%s',
                    $this->conversationDirectory($conversation),
                    $attachment->id,
                    $this->safeFilename($attachment),
                );
                $stream = $sourceDisk->readStream($attachment->storage_path);

                if ($stream === false) {
                    $unavailableFiles[] = $label;

                    return;
                }

                try {
                    if (! $destinationDisk->writeStream($path, $stream)) {
                        throw new RuntimeException("Failed to write exported file: {$path}");
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                $fileCount++;
            });

        return new ConversationExportResult($chatCount, $fileCount, $unavailableFiles);
    }

    private function conversationFilename(Conversation $conversation, ExportFormat $format): string
    {
        return $this->conversationDirectory($conversation).'.'.$format->value;
    }

    private function conversationDirectory(Conversation $conversation): string
    {
        $slug = Str::slug($conversation->title ?: 'conversation');

        return $conversation->id.'-'.Str::limit($slug ?: 'conversation', 80, '');
    }

    private function safeFilename(Attachment $attachment): string
    {
        $filename = $attachment->filename;

        if (! is_string($filename) || trim($filename) === '') {
            $filename = basename(str_replace('\\', '/', (string) $attachment->storage_path));
        }

        $filename = basename(str_replace('\\', '/', $filename));
        $filename = preg_replace('/[^\pL\pN._ -]+/u', '-', $filename) ?? '';
        $filename = trim($filename, " .-\t\n\r\0\x0B");

        return Str::limit($filename !== '' ? $filename : 'attachment', 180, '');
    }
}
