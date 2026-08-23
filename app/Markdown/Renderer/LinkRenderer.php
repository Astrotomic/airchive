<?php

namespace App\Markdown\Renderer;

use App\Managers\Favicons\FaviconManager;
use Illuminate\Support\Str;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

final readonly class LinkRenderer implements NodeRendererInterface
{
    public function __construct(private FaviconManager $favicons) {}

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?HtmlElement
    {
        assert($node instanceof Link);

        $url = $node->getUrl();
        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || ! in_array(Str::lower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
        ) {
            return null;
        }

        $host = Str::lower(rtrim($parts['host'], '.'));
        $domain = $host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $label = html_entity_decode(
            strip_tags($childRenderer->renderNodes($node->children())),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );

        $attributes = [
            'href' => $url,
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
            'title' => $url,
        ];

        if (! $this->isUrlLabel($label)) {
            return new HtmlElement(
                'a',
                [...$attributes, 'class' => 'markdown-link'],
                $childRenderer->renderNodes($node->children()),
            );
        }

        $fallback = new HtmlElement(
            'span',
            ['class' => 'markdown-link-chip__favicon', 'aria-hidden' => 'true'],
            [
                $this->escape(Str::upper(Str::substr($host, 0, 1))),
                new HtmlElement('img', [
                    'src' => $this->favicons->url($host),
                    'alt' => '',
                    'loading' => 'lazy',
                    'referrerpolicy' => 'origin',
                    'onerror' => 'this.remove()',
                ], '', true),
            ],
        );

        return new HtmlElement(
            'a',
            [...$attributes, 'class' => 'markdown-link-chip'],
            [
                $fallback,
                new HtmlElement('span', ['class' => 'markdown-link-chip__domain'], $this->escape($domain)),
            ],
        );
    }

    private function isUrlLabel(string $label): bool
    {
        return preg_match('~^https?://\S+$~iu', trim($label)) === 1;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
