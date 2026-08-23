<?php

namespace App\ValueObjects;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final readonly class IpInfo
{
    public static function fetch(string $ip): self
    {
        try {
            $response = Http::acceptJson()
                ->throw()
                ->timeout(3)
                ->connectTimeout(1)
                ->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,countryCode,region,city,zip',
                ]);

            if ($response->json('status') === 'fail') {
                throw new RequestException($response);
            }

            return new IpInfo(
                ip: $ip,
                countryCode: $response->json('countryCode'),
                regionCode: $response->json('region'),
                city: $response->json('city'),
                zip: $response->json('zip'),
            );
        } catch (HttpClientException) {
            return new IpInfo(
                ip: $ip,
            );
        }
    }

    public function __construct(
        public string $ip,
        public ?string $countryCode = null,
        public ?string $regionCode = null,
        public ?string $city = null,
        public ?string $zip = null,
    ) {}
}
