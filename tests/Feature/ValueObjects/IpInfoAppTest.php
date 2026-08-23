<?php

namespace Tests\Feature\ValueObjects;

use App\ValueObjects\IpInfo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AppTestCase;

class IpInfoAppTest extends AppTestCase
{
    public function test_it_fetches_ip_location_data(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'countryCode' => 'DE',
                'region' => 'BE',
                'city' => 'Berlin',
                'zip' => '10115',
            ]),
        ]);

        $info = IpInfo::fetch('8.8.8.8');

        Assert::assertSame('8.8.8.8', $info->ip);
        Assert::assertSame('DE', $info->countryCode);
        Assert::assertSame('BE', $info->regionCode);
        Assert::assertSame('Berlin', $info->city);
        Assert::assertSame('10115', $info->zip);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'http://ip-api.com/json/8.8.8.8?fields=status%2CcountryCode%2Cregion%2Ccity%2Czip'
            && $request->hasHeader('Accept', 'application/json'));
    }

    /** @param array<string, mixed> $response */
    #[DataProvider('unavailableResponses')]
    public function test_it_returns_ip_only_when_location_data_is_unavailable(array $response, int $status): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response($response, $status),
        ]);

        $info = IpInfo::fetch('192.0.2.1');

        Assert::assertSame('192.0.2.1', $info->ip);
        Assert::assertNull($info->countryCode);
        Assert::assertNull($info->regionCode);
        Assert::assertNull($info->city);
        Assert::assertNull($info->zip);
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function unavailableResponses(): iterable
    {
        yield 'API failure response' => [['status' => 'fail'], 200];
        yield 'HTTP error response' => [[], 500];
    }
}
