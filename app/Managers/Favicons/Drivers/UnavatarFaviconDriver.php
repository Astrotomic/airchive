<?php

namespace App\Managers\Favicons\Drivers;

use App\Contracts\FaviconDriver;
use Astrotomic\Unavatar\Unavatar;

final class UnavatarFaviconDriver implements FaviconDriver
{
    public function url(string $domain, int $size): string
    {
        return Unavatar::domain($domain)->toUrl();
    }
}
