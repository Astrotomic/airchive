<?php

namespace App\Contracts;

interface FaviconDriver
{
    public function url(string $domain, int $size): string;
}
