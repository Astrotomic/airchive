<?php

namespace Tests\Unit\ValueObjects;

use App\ValueObjects\IpInfo;
use PHPUnit\Framework\Assert;
use Tests\UnitTestCase;

class IpInfoTest extends UnitTestCase
{
    public function test_it_stores_ip_location_data(): void
    {
        $info = new IpInfo(
            ip: '8.8.8.8',
            countryCode: 'US',
            regionCode: 'CA',
            city: 'Mountain View',
            zip: '94043',
        );

        Assert::assertSame('8.8.8.8', $info->ip);
        Assert::assertSame('US', $info->countryCode);
        Assert::assertSame('CA', $info->regionCode);
        Assert::assertSame('Mountain View', $info->city);
        Assert::assertSame('94043', $info->zip);
    }

    public function test_location_data_defaults_to_null(): void
    {
        $info = new IpInfo('127.0.0.1');

        Assert::assertNull($info->countryCode);
        Assert::assertNull($info->regionCode);
        Assert::assertNull($info->city);
        Assert::assertNull($info->zip);
    }
}
