<?php

namespace Tests\Unit\Actions\Conversations;

use App\Actions\Conversations\ExtractExternalSources;
use App\Enums\BlockType;
use App\Models\ContentBlock;
use App\Models\Message;
use Astrotomic\PhpunitAssertions\ArrayAssertions;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class ExtractExternalSourcesTest extends TestCase
{
    public function test_extracts_labels_and_unique_urls_from_text_and_citation_metadata(): void
    {
        $message = new Message([
            'metadata' => [
                'content_references' => [
                    [
                        'type' => 'grouped_webpages',
                        'title' => 'Laravel routing documentation',
                        'url' => 'https://laravel.com/docs#routing',
                    ],
                    [
                        'type' => 'webpage',
                        'title' => 'OpenAI research',
                        'url' => 'https://openai.com/research/',
                    ],
                ],
            ],
        ]);
        $message->setRelation('contentBlocks', collect([
            new ContentBlock([
                'block_type' => BlockType::Text,
                'text_content' => 'Read [Laravel docs](https://laravel.com/docs/) and https://example.com/story. Again: https://example.com/story.',
                'structured_content' => [
                    'search_result_groups' => [[
                        'entries' => [[
                            'title' => 'OpenAI research duplicate',
                            'url' => 'https://openai.com/research#models',
                        ]],
                    ]],
                ],
            ]),
        ]));

        $sources = ExtractExternalSources::make()->execute(collect([$message]));
        $urls = $sources->pluck('url')->all();

        Assert::assertCount(3, $sources);
        ArrayAssertions::assertIndexed($urls);
        foreach ($urls as $url) {
            UrlAssertions::assertValidLoose($url);
        }
        Assert::assertSame(
            ['https://laravel.com/docs/', 'https://example.com/story', 'https://openai.com/research#models'],
            $urls,
        );
        ArrayAssertions::assertAssociative($sources[0]);
        Assert::assertSame('Laravel routing documentation', $sources[0]['label']);
        Assert::assertSame('example.com', $sources[1]['label']);
        Assert::assertSame('OpenAI research duplicate', $sources[2]['label']);
        Assert::assertSame('/docs/', $sources[0]['path']);
    }

    public function test_ignores_non_http_links_and_trims_prose_punctuation(): void
    {
        $message = new Message(['metadata' => []]);
        $message->setRelation('contentBlocks', collect([
            new ContentBlock([
                'block_type' => BlockType::Text,
                'text_content' => 'Try javascript:alert(1), mailto:test@example.com, https://www, or https://example.com/article?ref=chat#section).',
            ]),
        ]));

        $sources = ExtractExternalSources::make()->execute(collect([$message]));
        $source = $sources->sole();

        Assert::assertCount(1, $sources);
        ArrayAssertions::assertAssociative($source);
        UrlAssertions::assertValidLoose($source['url']);
        Assert::assertSame('https://example.com/article?ref=chat#section', $source['url']);
        Assert::assertSame('/article', $source['path']);
    }

    public function test_json_escaped_markdown_debris_does_not_create_extra_urls(): void
    {
        $message = new Message(['metadata' => []]);
        $message->setRelation('contentBlocks', collect([
            new ContentBlock([
                'block_type' => BlockType::ToolResult,
                'text_content' => 'Published by [Astrotomic](https://astrotomic.info/).\\n\\n## XML: xmlns:xsi=\\"http://www.w3.org/2001/XMLSchema-instance\\"\\n',
                'structured_content' => [
                    'contents' => "Published by [Astrotomic](https://astrotomic.info/).\n\n## XML: xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance/\"",
                ],
            ]),
        ]));

        $sources = ExtractExternalSources::make()->execute(collect([$message]));
        $urls = $sources->pluck('url')->all();
        $paths = $sources->pluck('path')->all();

        Assert::assertCount(2, $sources);
        ArrayAssertions::assertIndexed($urls);
        foreach ($urls as $url) {
            UrlAssertions::assertValidLoose($url);
        }
        Assert::assertSame(
            ['https://astrotomic.info/', 'http://www.w3.org/2001/XMLSchema-instance'],
            $urls,
        );
        ArrayAssertions::assertIndexed($paths);
        Assert::assertSame(['/', '/2001/XMLSchema-instance'], $paths);
    }

    public function test_tracking_variants_and_wikipedia_languages_are_canonicalized(): void
    {
        $message = new Message(['metadata' => []]);
        $message->setRelation('contentBlocks', collect([
            new ContentBlock([
                'block_type' => BlockType::Text,
                'text_content' => implode(' ', [
                    'https://en.wikipedia.org/wiki/Legion_of_Boom?utm_source=chatgpt.com',
                    'https://en.wikipedia.org/wiki/Legion_of_Boom',
                    'https://de.wikipedia.org/wiki/Super_Bowl_XLIX?utm_source=chatgpt.com',
                    'https://en.wikipedia.org/wiki/Super_Bowl_XLIX',
                    'https://en.wikipedia.org/wiki/Marshawn_Lynch?utm_medium=answer&utm_source=chatgpt.com',
                    'https://en.wikipedia.org/wiki/Marshawn_Lynch',
                ]),
            ]),
        ]));

        $sources = ExtractExternalSources::make()->execute(collect([$message]));
        $urls = $sources->pluck('url')->all();
        $groupHosts = $sources->pluck('group_host')->unique()->values()->all();

        Assert::assertCount(3, $sources);
        ArrayAssertions::assertIndexed($urls);
        foreach ($urls as $url) {
            UrlAssertions::assertValidLoose($url);
        }
        Assert::assertSame(
            [
                'https://en.wikipedia.org/wiki/Legion_of_Boom',
                'https://de.wikipedia.org/wiki/Super_Bowl_XLIX',
                'https://en.wikipedia.org/wiki/Marshawn_Lynch',
            ],
            $urls,
        );
        ArrayAssertions::assertIndexed($groupHosts);
        Assert::assertSame(['wikipedia.org'], $groupHosts);
    }

    public function test_www_and_bare_hosts_share_the_same_canonical_group(): void
    {
        $message = new Message(['metadata' => []]);
        $message->setRelation('contentBlocks', collect([
            new ContentBlock([
                'block_type' => BlockType::Text,
                'text_content' => 'https://www.example.com/b https://example.com/a',
            ]),
        ]));

        $sources = ExtractExternalSources::make()->execute(collect([$message]));
        $hosts = $sources->pluck('host')->all();
        $groupHosts = $sources->pluck('group_host')->unique()->values()->all();

        ArrayAssertions::assertIndexed($hosts);
        Assert::assertSame(['www.example.com', 'example.com'], $hosts);
        ArrayAssertions::assertIndexed($groupHosts);
        Assert::assertSame(['example.com'], $groupHosts);
    }
}
