<?php

namespace App\Actions\Imports;

use App\Actions\Action;
use App\Actions\Projects\AssignConversationToProjects;
use App\Models\Attachment;
use App\Models\ContentBlock;
use App\Models\Conversation;
use App\Models\ConversationSource;
use App\Models\Message;
use App\ValueObjects\CanonicalAttachment;
use App\ValueObjects\CanonicalContentBlock;
use App\ValueObjects\CanonicalConversation;
use App\ValueObjects\CanonicalConversationSource;
use App\ValueObjects\CanonicalMessage;
use App\ValueObjects\ImportContext;
use Illuminate\Support\Facades\DB;

class WriteCanonicalConversation extends Action
{
    public function execute(ImportContext $ctx, CanonicalConversation $canonical): Conversation
    {
        return DB::transaction(function () use ($ctx, $canonical): Conversation {
            $conversation = Conversation::query()->updateOrCreate(
                [
                    'user_id' => $ctx->userId,
                    'source_platform' => $canonical->sourcePlatform,
                    'source_conversation_id' => $canonical->sourceConversationId,
                ],
                [
                    'title' => $canonical->title,
                    'metadata' => $canonical->metadata,
                    'canonical_leaf_message_id' => null,
                ],
            );

            $this->replaceMessages($conversation, $canonical);

            $leafMessageId = null;

            if ($canonical->canonicalLeafSourceMessageId !== null) {
                $leafMessageId = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('source_message_id', $canonical->canonicalLeafSourceMessageId)
                    ->value('id');
            }

            if ($leafMessageId === null) {
                $leafMessageId = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('is_on_canonical_path', true)
                    ->orderByDesc('created_at')
                    ->value('id');
            }

            $firstMessageAt = $conversation->messages()->min('created_at');
            $lastMessageAt = $conversation->messages()->max('created_at');

            $conversation->update([
                'canonical_leaf_message_id' => $leafMessageId,
                'first_message_at' => $firstMessageAt,
                'last_message_at' => $lastMessageAt,
            ]);

            $this->recordSources($conversation, $canonical, $ctx);
            AssignConversationToProjects::make()->execute($conversation, $canonical->projectIdentifiers);

            return $conversation->refresh();
        });
    }

    private function replaceMessages(Conversation $conversation, CanonicalConversation $canonical): void
    {
        $conversation->messages()->delete();

        $idBySourceMessageId = [];

        foreach ($canonical->messages as $canonicalMessage) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'parent_message_id' => null,
                'source_message_id' => $canonicalMessage->sourceMessageId,
                'role' => $canonicalMessage->role,
                'actor_name' => $canonicalMessage->actorName,
                'created_at' => $canonicalMessage->createdAt,
                'is_on_canonical_path' => $canonicalMessage->isOnCanonicalPath,
                'is_hidden' => $canonicalMessage->isHidden,
                'metadata' => $canonicalMessage->metadata,
            ]);

            $idBySourceMessageId[$canonicalMessage->sourceMessageId] = $message->id;

            $this->persistBlocks($conversation, $message, $canonicalMessage);
        }

        foreach ($canonical->messages as $canonicalMessage) {
            if ($canonicalMessage->parentSourceMessageId === null) {
                continue;
            }

            $messageId = $idBySourceMessageId[$canonicalMessage->sourceMessageId] ?? null;
            $parentId = $idBySourceMessageId[$canonicalMessage->parentSourceMessageId] ?? null;

            if ($messageId === null || $parentId === null) {
                continue;
            }

            Message::query()
                ->whereKey($messageId)
                ->update(['parent_message_id' => $parentId]);
        }
    }

    private function persistBlocks(
        Conversation $conversation,
        Message $message,
        CanonicalMessage $canonicalMessage,
    ): void {
        foreach ($canonicalMessage->blocks as $block) {
            if ($this->shouldSkipBlock($block)) {
                continue;
            }

            $contentBlock = ContentBlock::query()->create([
                'message_id' => $message->id,
                'position' => $block->position,
                'block_type' => $block->blockType,
                'text_content' => $block->textContent,
                'structured_content' => $block->structuredContent,
                'language' => $block->language,
                'metadata' => $block->metadata,
            ]);

            foreach ($block->attachments as $attachment) {
                $this->persistAttachment($conversation, $message, $attachment, $contentBlock);
            }
        }

        foreach ($canonicalMessage->attachments as $attachment) {
            $this->persistAttachment($conversation, $message, $attachment);
        }
    }

    private function persistAttachment(
        Conversation $conversation,
        Message $message,
        CanonicalAttachment $attachment,
        ?ContentBlock $contentBlock = null,
    ): void {
        $sourceAttachmentId = filled($attachment->sourceAttachmentId)
            ? $attachment->sourceAttachmentId
            : null;

        $attributes = [
            'user_id' => $conversation->user_id,
            'conversation_id' => $conversation->id,
            'source_platform' => $conversation->source_platform->value,
            'attachment_type' => $attachment->attachmentType,
            'filename' => $attachment->filename,
            'mime_type' => $attachment->mimeType,
            'byte_size' => $attachment->byteSize,
            'checksum' => $attachment->checksum,
            'storage_path' => $attachment->storagePath,
            'external_url' => $attachment->externalUrl,
            'source_ref' => $attachment->sourceRef,
        ];

        if ($contentBlock !== null) {
            $attributes['content_block_id'] = $contentBlock->id;
        }

        if ($sourceAttachmentId === null) {
            Attachment::query()->create([
                'message_id' => $message->id,
                ...$attributes,
            ]);

            return;
        }

        Attachment::query()->updateOrCreate(
            [
                'message_id' => $message->id,
                'source_platform' => $conversation->source_platform->value,
                'source_attachment_id' => $sourceAttachmentId,
            ],
            $attributes,
        );
    }

    private function shouldSkipBlock(CanonicalContentBlock $block): bool
    {
        $text = trim((string) ($block->textContent ?? ''));

        if ($text !== '') {
            return false;
        }

        if ($block->attachments !== []) {
            return false;
        }

        $structured = $block->structuredContent;

        return $structured === null || $structured === [];
    }

    private function recordSources(
        Conversation $conversation,
        CanonicalConversation $canonical,
        ImportContext $ctx,
    ): void {
        $sources = $canonical->sources !== []
            ? $canonical->sources
            : [CanonicalConversationSource::fromImportContext($ctx)];

        foreach ($sources as $source) {
            ConversationSource::query()->updateOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'source_file' => $source->sourceFile,
                    'source_format' => $source->sourceFormat,
                    'raw_checksum' => $source->rawChecksum,
                ],
                [
                    'imported_at' => now(),
                    'raw_storage_path' => $source->rawStoragePath,
                ],
            );
        }
    }
}
