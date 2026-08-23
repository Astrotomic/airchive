<?php

namespace App\Managers\Favicons;

use App\Contracts\FaviconDriver;
use App\Managers\Favicons\Drivers\GoogleFaviconDriver;
use App\Managers\Favicons\Drivers\LogoDevFaviconDriver;
use App\Managers\Favicons\Drivers\UnavatarFaviconDriver;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Manager;

final class FaviconManager extends Manager
{
    public function url(string $domain): string
    {
        /** @var FaviconDriver $driver */
        $driver = $this->driver();

        return $driver->url($domain, Config::integer('favicons.size', 128));
    }

    public function getDefaultDriver(): string
    {
        return Config::string('favicons.default', 'gstatic');
    }

    protected function createGstaticDriver(): FaviconDriver
    {
        return new GoogleFaviconDriver;
    }

    protected function createUnavatarDriver(): FaviconDriver
    {
        return new UnavatarFaviconDriver;
    }

    protected function createLogoDevDriver(): FaviconDriver
    {
        return new LogoDevFaviconDriver(Config::string('favicons.drivers.logo_dev.token'));
    }
}
