<?php

return [
    'default' => env('FAVICON_DRIVER', 'gstatic'),

    'size' => (int) env('FAVICON_SIZE', 128),

    'drivers' => [
        'gstatic' => [],
        'unavatar' => [],
        'logo_dev' => [
            'token' => env('LOGO_DEV_TOKEN'),
        ],
    ],
];
