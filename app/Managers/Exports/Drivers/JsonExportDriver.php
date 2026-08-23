<?php

namespace App\Managers\Exports\Drivers;

use App\Contracts\ConversationExportDriver;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use RuntimeException;

final class JsonExportDriver implements ConversationExportDriver
{
    public function export(Conversation $conversation): string
    {
        $payload = [
            'id' => $conversation->id,
            'title' => $conversation->title,
            'source_platform' => $conversation->source_platform->value,
            'source_conversation_id' => $conversation->source_conversation_id,
            'metadata' => $conversation->metadata ?? [],
            'canonical_leaf_message_id' => $conversation->canonical_leaf_message_id,
            'messages' => $this->canonicalMessages($conversation)
                ->map(fn (Message $message): array => $this->serializeMessage($message))
                ->values()
                ->all(),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Failed to encode conversation as JSON.');
        }

        return $encoded."\n";
    }

    /**
     * @return Collection<int, Message>
     */
    private function canonicalMessages(Conversation $conversation): Collection
    {
        return $conversation->messages()
            ->where('is_on_canonical_path', true)
            ->orderBy('created_at')
            ->orderBy('id')
            ->with(['attachments', 'contentBlocks.attachments'])
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'source_message_id' => $message->source_message_id,
            'parent_message_id' => $message->parent_message_id,
            'role' => $message->role->value,
            'actor_name' => $message->actor_name,
            'created_at' => $message->created_at?->toIso8601String(),
            'is_on_canonical_path' => $message->is_on_canonical_path,
            'is_hidden' => $message->is_hidden,
            'metadata' => $message->metadata ?? [],
            'attachments' => $message->attachments
                ->map(fn (Attachment $attachment): array => $this->serializeAttachment($attachment))
                ->values()
                ->all(),
            'content_blocks' => $message->contentBlocks
                ->map(fn ($block): array => [
                    'id' => $block->id,
                    'position' => $block->position,
                    'block_type' => $block->block_type->value,
                    'text_content' => $block->text_content,
                    'structured_content' => $block->structured_content,
                    'language' => $block->language,
                    'metadata' => $block->metadata ?? [],
                    'attachments' => $block->attachments
                        ->map(fn (Attachment $attachment): array => $this->serializeAttachment($attachment))
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'source_platform' => $attachment->source_platform?->value,
            'source_attachment_id' => $attachment->source_attachment_id,
            'attachment_type' => $attachment->attachment_type->value,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'byte_size' => $attachment->byte_size,
            'checksum' => $attachment->checksum,
            'storage_path' => $attachment->storage_path,
            'external_url' => $attachment->external_url,
            'source_ref' => $attachment->source_ref,
        ];
    }
}
