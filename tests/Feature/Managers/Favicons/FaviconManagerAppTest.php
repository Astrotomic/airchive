<?php

namespace Tests\Feature\Managers\Favicons;

use App\Managers\Favicons\FaviconManager;
use Astrotomic\PhpunitAssertions\UrlAssertions;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\AppTestCase;

class FaviconManagerAppTest extends AppTestCase
{
    public function test_it_builds_google_favicon_urls_by_default(): void
    {
        config()->set('favicons.default', 'gstatic');
        config()->set('favicons.size', 64);

        $url = app(FaviconManager::class)->url('example.com');

        UrlAssertions::assertValidLoose($url);
        UrlAssertions::assertScheme('https', $url);
        UrlAssertions::assertHost('t1.gstatic.com', $url);
        UrlAssertions::assertPath('/faviconV2', $url);
        UrlAssertions::assertQuery([
            'client' => 'SOCIAL',
            'type' => 'FAVICON',
            'fallback_opts' => 'TYPE,SIZE,URL',
            'url' => 'https://example.com',
            'size' => '64',
        ], $url);
        Assert::assertStringStartsWith('https://t1.gstatic.com/faviconV2?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        Assert::assertSame('https://example.com', $query['url']);
        Assert::assertSame('64', $query['size']);
    }

    public function test_it_supports_unavatar(): void
    {
        config()->set('favicons.default', 'unavatar');

        $url = app(FaviconManager::class)->url('example.com');

        UrlAssertions::assertValidLoose($url);
        Assert::assertSame('https://unavatar.io/example.com', $url);
    }

    public function test_logo_dev_requires_and_uses_a_publishable_token(): void
    {
        config()->set('favicons.default', 'logo_dev');
        config()->set('favicons.drivers.logo_dev.token', 'pk_test');

        $url = app(FaviconManager::class)->url('example.com');

        UrlAssertions::assertValidLoose($url);
        UrlAssertions::assertScheme('https', $url);
        UrlAssertions::assertHost('img.logo.dev', $url);
        UrlAssertions::assertPath('/example.com', $url);
        UrlAssertions::assertQuery([
            'token' => 'pk_test',
            'size' => '128',
            'format' => 'png',
            'retina' => 'true',
            'fallback' => 'monogram',
        ], $url);
        Assert::assertStringStartsWith('https://img.logo.dev/example.com?', $url);
        Assert::assertStringContainsString('token=pk_test', $url);
    }

    public function test_logo_dev_reports_a_missing_token(): void
    {
        config()->set('favicons.default', 'logo_dev');
        config()->set('favicons.drivers.logo_dev.token');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configuration value for key [favicons.drivers.logo_dev.token] must be a string, NULL given.',
        );

        app(FaviconManager::class)->url('example.com');
    }
}
