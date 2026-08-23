<?php

namespace Tests\Unit\View\Components;

use Astrotomic\PhpunitAssertions\EmailAssertions;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class MarkdownTest extends TestCase
{
    public function test_it_renders_url_labels_as_favicon_domain_chips(): void
    {
        config()->set('favicons.default', 'gstatic');

        $html = $this->renderMarkdown(
            '[https://Astrotomic.info/path?ref=chat](https://Astrotomic.info/path?ref=chat)',
        );

        UrlAssertions::assertValidLoose($this->firstAttribute($html, 'a', 'href'));
        UrlAssertions::assertValidLoose($this->firstAttribute($html, 'a', 'title'));
        UrlAssertions::assertValidLoose($this->firstAttribute($html, 'img', 'src'));
        Assert::assertStringContainsString('class="markdown-link-chip"', $html);
        Assert::assertStringContainsString('href="https://Astrotomic.info/path?ref=chat"', $html);
        Assert::assertStringContainsString('title="https://Astrotomic.info/path?ref=chat"', $html);
        Assert::assertStringContainsString('target="_blank"', $html);
        Assert::assertStringContainsString('rel="noopener noreferrer"', $html);
        Assert::assertStringContainsString('>astrotomic.info</span>', $html);
        Assert::assertStringContainsString('src="https://t1.gstatic.com/faviconV2?', $html);
        Assert::assertStringNotContainsString('>https://Astrotomic.info/path?ref=chat</a>', $html);
    }

    public function test_it_auto_links_bare_urls_as_domain_chips(): void
    {
        $html = $this->renderMarkdown('Visit https://astrotomic.info/docs today.');

        UrlAssertions::assertValidLoose($this->firstAttribute($html, 'a', 'href'));
        Assert::assertStringContainsString('Visit <a href="https://astrotomic.info/docs"', $html);
        Assert::assertStringContainsString('class="markdown-link-chip"', $html);
        Assert::assertStringContainsString('>astrotomic.info</span>', $html);
    }

    public function test_it_keeps_descriptive_external_link_labels(): void
    {
        $html = $this->renderMarkdown('[Astrotomic website](https://astrotomic.info/)');

        UrlAssertions::assertValidLoose($this->firstAttribute($html, 'a', 'href'));
        Assert::assertStringContainsString('class="markdown-link"', $html);
        Assert::assertStringContainsString('>Astrotomic website</a>', $html);
        Assert::assertStringNotContainsString('class="markdown-link-chip"', $html);
    }

    public function test_it_leaves_non_web_links_to_the_safe_default_renderer(): void
    {
        $html = $this->renderMarkdown('[Email us](mailto:hello@example.com)');
        $href = $this->firstAttribute($html, 'a', 'href');

        Assert::assertStringStartsWith('mailto:', $href);
        EmailAssertions::assertValidStrict(substr($href, strlen('mailto:')));
        Assert::assertStringContainsString('<a href="mailto:hello@example.com">Email us</a>', $html);
        Assert::assertStringNotContainsString('target="_blank"', $html);
    }

    private function renderMarkdown(string $markdown): string
    {
        return Blade::render('<x-markdown :content="$content" />', ['content' => $markdown]);
    }

    private function firstAttribute(string $html, string $tag, string $attribute): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $element = $document->getElementsByTagName($tag)->item(0);
        Assert::assertInstanceOf(DOMElement::class, $element);

        return $element->getAttribute($attribute);
    }
}
