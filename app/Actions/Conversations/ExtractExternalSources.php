<?php

namespace App\Actions\Conversations;

use App\Actions\Action;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class ExtractExternalSources extends Action
{
    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<int, array{url: string, label: string, host: string, group_host: string, path: string}>
     */
    public function execute(Collection $messages): Collection
    {
        /** @var array<string, array{url: string, label: string, label_priority: int, host: string, group_host: string, path: string}> $sources */
        $sources = [];

        foreach ($messages as $message) {
            foreach ($message->contentBlocks as $block) {
                $this->extractFromValue($block->text_content, $sources);
                $this->extractFromValue($block->structured_content, $sources);
                $this->extractFromValue($block->metadata, $sources);
            }

            // ChatGPT and similar exports keep citation targets in message metadata,
            // even when their visible text only contains an opaque turncite marker.
            $this->extractFromValue($message->metadata, $sources);
        }

        return collect($sources)
            ->map(fn (array $source): array => collect($source)->except('label_priority')->all())
            ->values();
    }

    /**
     * @param  array<string, array{url: string, label: string, label_priority: int, host: string, group_host: string, path: string}>  $sources
     */
    private function extractFromValue(mixed $value, array &$sources, ?string $inheritedLabel = null): void
    {
        if (is_string($value)) {
            $this->extractFromText($value, $sources, $inheritedLabel);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        $label = $this->labelFromArray($value) ?? $inheritedLabel;

        foreach (['url', 'source_url', 'href', 'link_url', 'external_url'] as $urlKey) {
            if (is_string($value[$urlKey] ?? null)) {
                $this->addSource($value[$urlKey], $label, $label === null ? 0 : 3, $sources);
            }
        }

        foreach ($value as $item) {
            $this->extractFromValue($item, $sources, $label);
        }
    }

    /**
     * @param  array<mixed>  $value
     */
    private function labelFromArray(array $value): ?string
    {
        foreach (['title', 'name', 'label'] as $key) {
            $label = $value[$key] ?? null;

            if (! is_string($label) || preg_match('~https?://~i', $label) === 1) {
                continue;
            }

            $label = $this->normalizeLabel($label);

            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array{url: string, label: string, label_priority: int, host: string, group_host: string, path: string}>  $sources
     */
    private function extractFromText(string $text, array &$sources, ?string $inheritedLabel = null): void
    {
        if ($text === '') {
            return;
        }

        if (preg_match_all('~\[([^\]]+)]\(\s*(https?://[^\s<>\x{005C}]+?)\s*(?:["\'][^"\']*["\'])?\)~iu', $text, $markdownLinks, PREG_SET_ORDER)) {
            foreach ($markdownLinks as $link) {
                $this->addSource($link[2], $this->normalizeLabel($link[1]), 2, $sources);
            }
        }

        if (preg_match_all('~<a\b[^>]*\bhref=["\'](https?://[^"\'\x{005C}]+)["\'][^>]*>(.*?)</a>~isu', $text, $htmlLinks, PREG_SET_ORDER)) {
            foreach ($htmlLinks as $link) {
                $this->addSource($link[1], $this->normalizeLabel(strip_tags($link[2])), 2, $sources);
            }
        }

        if (preg_match_all('~https?://[^\s<>"\'\x{005C}]+~iu', $text, $plainUrls)) {
            foreach ($plainUrls[0] as $url) {
                $this->addSource($url, $inheritedLabel, $inheritedLabel === null ? 0 : 1, $sources);
            }
        }
    }

    /**
     * @param  array<string, array{url: string, label: string, label_priority: int, host: string, group_host: string, path: string}>  $sources
     */
    private function addSource(string $candidate, ?string $label, int $labelPriority, array &$sources): void
    {
        $url = $this->cleanUrl($candidate);

        if ($url === null) {
            return;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return;
        }

        $host = Str::lower(rtrim($parts['host'], '.'));
        $groupHost = $this->groupHost($host);
        [$query, $queryKey] = $this->normalizeQuery($parts['query'] ?? null);
        $url = $this->normalizedUrl($parts, $host, $query);
        $key = $this->uniqueKey($parts, $groupHost, $queryKey);
        $label = $this->normalizeLabel((string) $label);

        if (isset($sources[$key])) {
            if ($label !== null && $labelPriority > $sources[$key]['label_priority']) {
                $sources[$key]['label'] = $label;
                $sources[$key]['label_priority'] = $labelPriority;
            }

            return;
        }

        $path = rawurldecode((string) ($parts['path'] ?? ''));

        $sources[$key] = [
            'url' => $url,
            'label' => $label ?? $host,
            'label_priority' => $label === null ? 0 : $labelPriority,
            'host' => $host,
            'group_host' => $groupHost,
            'path' => $path === '' ? '/' : $path,
        ];
    }

    private function cleanUrl(string $candidate): ?string
    {
        $url = trim(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $url = trim($url, "<>\"'");
        $url = rtrim($url, '.,;:!?');

        foreach ([')' => '(', ']' => '[', '}' => '{'] as $closing => $opening) {
            while (str_ends_with($url, $closing) && substr_count($url, $closing) > substr_count($url, $opening)) {
                $url = substr($url, 0, -1);
            }
        }

        $parts = parse_url($url);

        if (
            ! is_array($parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || str_contains($url, '\\')
            || ! in_array(Str::lower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || ! $this->isValidExternalHost($parts['host'])
        ) {
            return null;
        }

        return $url;
    }

    private function isValidExternalHost(string $host): bool
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function groupHost(string $host): string
    {
        foreach (Config::array('external-sources.host_groups', []) as $group) {
            $pattern = is_array($group) ? ($group['pattern'] ?? null) : null;
            $canonical = is_array($group) ? ($group['canonical'] ?? null) : null;

            if (is_string($pattern) && is_string($canonical) && preg_match($pattern, $host) === 1) {
                return $canonical;
            }
        }

        return Config::boolean('external-sources.strip_www', true) && str_starts_with($host, 'www.')
            ? Str::after($host, 'www.')
            : $host;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizeQuery(mixed $query): array
    {
        if (! is_string($query) || $query === '') {
            return [null, null];
        }

        $parameters = collect(explode('&', $query))
            ->filter(function (string $parameter): bool {
                $name = Str::lower(rawurldecode(Str::before($parameter, '=')));

                return ! Str::is(Config::array('external-sources.ignored_query_parameters', []), $name);
            })
            ->values();

        if ($parameters->isEmpty()) {
            return [null, null];
        }

        $canonical = $parameters->sort(SORT_STRING)->implode('&');

        return [$parameters->implode('&'), $canonical];
    }

    /** @param array<string, mixed> $parts */
    private function normalizedUrl(array $parts, string $host, ?string $query): string
    {
        $scheme = Str::lower((string) $parts['scheme']);
        $port = isset($parts['port']) && ! (($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
            ? ':'.$parts['port']
            : '';
        $path = (string) ($parts['path'] ?? '');
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.'://'.$host.$port.$path.($query === null ? '' : '?'.$query).$fragment;
    }

    /** @param array<string, mixed> $parts */
    private function uniqueKey(array $parts, string $groupHost, ?string $query): string
    {
        $scheme = Str::lower((string) $parts['scheme']);
        $port = isset($parts['port']) && ! (($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
            ? ':'.$parts['port']
            : '';
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $groupHost.$port.$path.($query === null ? '' : '?'.$query);
    }

    private function normalizeLabel(string $label): ?string
    {
        $label = html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = preg_replace('/[*_`~]+/u', '', $label) ?? $label;
        $label = preg_replace('/\s+/u', ' ', trim($label)) ?? trim($label);

        return $label === '' ? null : Str::limit($label, 120);
    }
}
