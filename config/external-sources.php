<?php

return [
    'ignored_query_parameters' => [
        'utm_*',
        'fbclid',
        'gclid',
        'dclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
    ],

    'strip_www' => true,

    'host_groups' => [
        [
            'canonical' => 'wikipedia.org',
            'pattern' => '/^(?:[a-z0-9-]+\.)?wikipedia\.org$/i',
        ],
    ],
];
