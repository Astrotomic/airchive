<?php

namespace Tests\Unit\Actions\Imports;

use App\Actions\Imports\ParseChatGptConversation;
use App\Enums\AttachmentType;
use App\Enums\BlockType;
use App\Enums\ImportFormat;
use App\Enums\MessageRole;
use App\Enums\SourcePlatform;
use App\ValueObjects\CanonicalMessage;
use App\ValueObjects\ImportContext;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\UnitTestCase;

class ParseChatGptConversationTest extends UnitTestCase
{
    public function test_it_parses_single_and_list_exports_and_filters_invalid_list_entries(): void
    {
        $conversation = $this->conversation([
            'message-1' => $this->node('message-1', null, $this->message('user', [
                'content_type' => 'text',
                'parts' => ['Hello'],
            ])),
        ]);
        $action = ParseChatGptConversation::make();

        $single = $action->execute($this->context(), json_encode($conversation, JSON_THROW_ON_ERROR));
        $list = $action->execute($this->context(), [null, ['other' => true], $conversation]);

        Assert::assertCount(1, $single);
        Assert::assertCount(1, $list);
        Assert::assertSame('conversation-1', $single[0]->sourceConversationId);
        Assert::assertSame('message-1', $single[0]->canonicalLeafSourceMessageId);
        Assert::assertSame(SourcePlatform::ChatGpt, $single[0]->sourcePlatform);
        Assert::assertSame('export.json', $single[0]->sources[0]->sourceFile);
    }

    #[DataProvider('invalidExports')]
    public function test_it_rejects_invalid_exports(string|array $contents, string $message): void
    {
        try {
            ParseChatGptConversation::make()->execute($this->context(), $contents);
            Assert::fail('Invalid ChatGPT export was accepted.');
        } catch (InvalidArgumentException $exception) {
            Assert::assertSame($message, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string|array<array-key, mixed>, string}> */
    public static function invalidExports(): iterable
    {
        yield 'invalid JSON' => ['{', 'ChatGPT export is not valid JSON.'];
        yield 'unsupported object' => [['other' => true], 'ChatGPT export JSON must contain mapping and current_node.'];
        yield 'empty mapping' => [[
            'mapping' => [],
            'current_node' => 'message-1',
        ], 'ChatGPT export is missing mapping or current_node.'];
        yield 'empty current node' => [[
            'mapping' => ['message-1' => []],
            'current_node' => '',
        ], 'ChatGPT export is missing mapping or current_node.'];
    }

    public function test_it_parses_message_identity_path_timestamps_and_metadata(): void
    {
        $data = [
            'id' => 'id-fallback',
            'conversation_id' => 'conversation-preferred',
            'title' => 'Metadata test',
            'current_node' => 'child',
            'mapping' => [
                'invalid-node' => 'skip',
                'missing-message' => ['parent' => null],
                'root' => $this->node('root', null, [
                    'author' => 'invalid',
                    'content' => ['content_type' => 'text', 'parts' => ['Root']],
                    'create_time' => null,
                    'metadata' => 'invalid',
                    'recipient' => 'all',
                ]),
                'child' => $this->node('child', 'root', [
                    'author' => ['role' => 'assistant', 'name' => 'Assistant'],
                    'content' => ['content_type' => 'text', 'parts' => ['Child']],
                    'create_time' => '1700000000',
                    'metadata' => ['model_slug' => 'gpt-5'],
                    'status' => 'finished',
                ]),
                'branch' => $this->node('branch', 123, [
                    'author' => ['role' => 'custom'],
                    'content' => ['content_type' => 'text', 'parts' => ['Branch']],
                    'create_time' => 'not-numeric',
                ]),
            ],
            'project_id' => 'g-p-project-1',
            'extra' => true,
        ];

        $conversation = ParseChatGptConversation::make()->execute($this->context(), $data)[0];
        $messages = $this->messagesById($conversation->messages);

        Assert::assertSame('conversation-preferred', $conversation->sourceConversationId);
        Assert::assertSame(['root', 'child'], array_keys(array_filter(
            $messages,
            static fn (CanonicalMessage $message): bool => $message->isOnCanonicalPath,
        )));
        Assert::assertSame(MessageRole::Unknown, $messages['root']->role);
        Assert::assertNull($messages['root']->actorName);
        Assert::assertNull($messages['root']->createdAt);
        Assert::assertSame('root', $messages['child']->parentSourceMessageId);
        Assert::assertSame('Assistant', $messages['child']->actorName);
        Assert::assertSame(1_700_000_000, $messages['child']->createdAt?->timestamp);
        Assert::assertNull($messages['branch']->parentSourceMessageId);
        Assert::assertNull($messages['branch']->createdAt);
        Assert::assertSame('finished', $messages['child']->metadata['_source']['status']);
        Assert::assertSame('gpt-5', $messages['child']->metadata['model_slug']);
        Assert::assertArrayNotHasKey('mapping', $conversation->metadata);
        Assert::assertArrayNotHasKey('title', $conversation->metadata);
        Assert::assertTrue($conversation->metadata['extra']);
        Assert::assertCount(1, $conversation->projectIdentifiers);
    }

    public function test_it_uses_title_id_and_uuid_fallbacks(): void
    {
        $mapping = ['message-1' => $this->node('message-1', null, $this->message('user', [
            'content_type' => 'text',
            'parts' => ['Hello'],
        ]))];
        $idFallback = ParseChatGptConversation::make()->execute($this->context(), [
            'id' => 'id-fallback',
            'current_node' => 'message-1',
            'mapping' => $mapping,
        ])[0];
        $uuidFallback = ParseChatGptConversation::make()->execute($this->context(), [
            'current_node' => 'message-1',
            'mapping' => $mapping,
        ])[0];

        Assert::assertSame('Untitled conversation', $idFallback->title);
        Assert::assertSame('id-fallback', $idFallback->sourceConversationId);
        Assert::assertTrue(Str::isUuid($uuidFallback->sourceConversationId));
    }

    public function test_it_maps_specialized_content_blocks_and_payload_fallbacks(): void
    {
        $mapping = [
            'tool-use' => $this->node('tool-use', null, $this->message('assistant', [
                'content_type' => 'code',
                'text' => '{"path":"/tmp/file"}',
            ], ['recipient' => 'Read'])),
            'tool-result' => $this->node('tool-result', 'tool-use', $this->message('tool', [
                'content_type' => 'text',
                'parts' => [''],
            ], [
                'author' => ['role' => 'tool', 'name' => 'Read'],
                'metadata' => ['result' => 'contents'],
            ])),
            'empty-tool-result' => $this->node('empty-tool-result', 'tool-result', $this->message('tool', [], [
                'metadata' => [],
            ])),
            'code' => $this->node('code', 'empty-tool-result', $this->message('assistant', [
                'content_type' => 'code',
                'parts' => [['text' => '<?php'], ['content' => 'echo 1;'], 42],
            ], ['metadata' => ['language' => 'php']])),
            'thoughts' => $this->node('thoughts', 'code', $this->message('assistant', [
                'content_type' => 'thoughts',
                'thoughts' => [
                    ['content' => 'First thought'],
                    ['summary' => 'Second thought'],
                    'invalid',
                ],
            ])),
            'recap' => $this->node('recap', 'thoughts', $this->message('assistant', [
                'content_type' => 'reasoning_recap',
                'content' => 'Reasoning recap',
            ])),
            'computer' => $this->node('computer', 'recap', $this->message('assistant', [
                'content_type' => 'computer_output',
                'screenshot' => ['asset_pointer' => 'file-service://file-shot1.png', 'size_bytes' => '25'],
            ])),
            'execution' => $this->node('execution', 'computer', $this->message('assistant', [
                'content_type' => 'execution_output',
                'text' => 'Command output',
            ])),
        ];
        $data = $this->conversation($mapping, 'execution');

        $messages = $this->messagesById(
            ParseChatGptConversation::make()->execute($this->context(), $data)[0]->messages,
        );

        Assert::assertSame(BlockType::ToolUse, $messages['tool-use']->blocks[0]->blockType);
        Assert::assertSame(['path' => '/tmp/file'], $messages['tool-use']->blocks[0]->structuredContent['input']);
        Assert::assertSame(BlockType::ToolResult, $messages['tool-result']->blocks[0]->blockType);
        Assert::assertSame(['result' => 'contents'], $messages['tool-result']->blocks[0]->structuredContent['output']);
        Assert::assertSame([], $messages['empty-tool-result']->blocks);
        Assert::assertSame(BlockType::Code, $messages['code']->blocks[0]->blockType);
        Assert::assertSame("<?php\necho 1;", $messages['code']->blocks[0]->textContent);
        Assert::assertSame('php', $messages['code']->blocks[0]->language);
        Assert::assertSame("First thought\n\nSecond thought", $messages['thoughts']->blocks[0]->textContent);
        Assert::assertSame(BlockType::Reasoning, $messages['recap']->blocks[0]->blockType);
        Assert::assertSame(BlockType::Image, $messages['computer']->blocks[0]->blockType);
        Assert::assertSame(25, $messages['computer']->blocks[0]->attachments[0]->byteSize);
        Assert::assertSame(BlockType::ToolResult, $messages['execution']->blocks[0]->blockType);
    }

    public function test_it_parses_multimodal_parts_citations_and_attachments(): void
    {
        $message = $this->message('assistant', [
            'content_type' => 'multimodal_text',
            'parts' => [
                'Visible citeturn1',
                ' ',
                42,
                ['type' => 'image', 'image_url' => ['url' => 'https://example.com/image.png']],
                ['asset_pointer' => 'file-service://folder/file-local1.png'],
                ['text' => 'Part text'],
                ['custom' => true],
                [],
            ],
        ], [
            'metadata' => [
                'content_references' => [
                    ['type' => 'grouped_webpages', 'matched_text' => 'citeturn1', 'alt' => '[Source](https://example.com)'],
                    ['type' => 'sources_footnote', 'matched_text' => 'Visible', 'alt' => 'Hidden'],
                    'invalid',
                ],
                'attachments' => [
                    ['id' => 'file-image1', 'mime_type' => 'image/png', 'name' => 'photo.png', 'size' => '12'],
                    ['file_id' => 'file_audio2', 'mimeType' => 'audio/mpeg'],
                    ['library_file_id' => 'invalid'],
                    'invalid',
                ],
            ],
            'nested' => [
                'image_pointer' => 'sediment://folder/file-shot3.png',
                'other_pointer' => 'file-service://folder/file-doc4?download=1',
                'screenshot' => 'file-service://file-image1',
                'asset_pointer' => 'file-service://file-asset5',
            ],
        ]);
        $data = $this->conversation([
            'message-1' => $this->node('message-1', null, $message),
        ]);

        $canonical = ParseChatGptConversation::make()->execute($this->context(), $data)[0];
        $parsed = $canonical->messages[0];

        Assert::assertSame([
            BlockType::Text,
            BlockType::Image,
            BlockType::Image,
            BlockType::Text,
            BlockType::Other,
        ], array_column($parsed->blocks, 'blockType'));
        Assert::assertSame('Visible [Source](https://example.com)', $parsed->blocks[0]->textContent);
        Assert::assertSame('https://example.com/image.png', $parsed->blocks[1]->textContent);
        Assert::assertSame('https://example.com/image.png', $parsed->blocks[1]->attachments[0]->externalUrl);
        Assert::assertSame('file-local1', $parsed->blocks[2]->attachments[0]->sourceAttachmentId);
        Assert::assertSame([
            'file-image1',
            'file_audio2',
            'file-local1',
            'file-shot3',
            'file-doc4',
            'file-asset5',
        ], array_column($parsed->attachments, 'sourceAttachmentId'));
        Assert::assertSame(AttachmentType::Image, $parsed->attachments[0]->attachmentType);
        Assert::assertSame(AttachmentType::Audio, $parsed->attachments[1]->attachmentType);
        Assert::assertSame(12, $parsed->attachments[0]->byteSize);
        Assert::assertSame(AttachmentType::File, $parsed->attachments[4]->attachmentType);
        Assert::assertSame(AttachmentType::Image, $parsed->attachments[5]->attachmentType);
    }

    private function context(): ImportContext
    {
        return new ImportContext(
            userId: 1,
            filePath: 'imports/export.json',
            sourceFormat: ImportFormat::ChatGptJson,
            rawChecksum: 'checksum',
            sourceFile: 'export.json',
        );
    }

    /**
     * @param  array<string, array<string, mixed>|string>  $mapping
     * @return array<string, mixed>
     */
    private function conversation(array $mapping, string $currentNode = 'message-1'): array
    {
        return [
            'title' => 'Conversation',
            'conversation_id' => 'conversation-1',
            'current_node' => $currentNode,
            'mapping' => $mapping,
        ];
    }

    /** @param array<string, mixed> $message */
    private function node(string $id, mixed $parent, array $message): array
    {
        return ['id' => $id, 'parent' => $parent, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function message(string $role, array $content, array $extra = []): array
    {
        return [
            'author' => ['role' => $role],
            'content' => $content,
            ...$extra,
        ];
    }

    /**
     * @param  array<int, CanonicalMessage>  $messages
     * @return array<string, CanonicalMessage>
     */
    private function messagesById(array $messages): array
    {
        $indexed = [];

        foreach ($messages as $message) {
            $indexed[$message->sourceMessageId] = $message;
        }

        return $indexed;
    }
}
