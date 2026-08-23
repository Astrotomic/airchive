<?php

namespace App\View\Components;

use App\Managers\Favicons\FaviconManager;
use App\Markdown\Renderer\LinkRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\GithubFlavoredMarkdownConverter;

final class Markdown extends Component
{
    public readonly string $html;

    public function __construct(string $content, FaviconManager $favicons)
    {
        $content = trim($content);

        if ($content === '') {
            $this->html = '';

            return;
        }

        $converter = new GithubFlavoredMarkdownConverter;
        $converter->getEnvironment()->addRenderer(
            Link::class,
            new LinkRenderer($favicons),
            100,
        );

        $this->html = (string) $converter->convert($content);
    }

    public function render(): View
    {
        return view('components.markdown');
    }
}
