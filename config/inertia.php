<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inertia Page Components
    |--------------------------------------------------------------------------
    |
    | This project uses the lowercase "pages" directory used by the React
    | starter kit. Keep the application and testing page paths aligned.
    |
    */

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'page_extensions' => [
        'js',
        'jsx',
        'ts',
        'tsx',
    ],

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [
            resource_path('js/pages'),
        ],
        'page_extensions' => [
            'js',
            'jsx',
            'ts',
            'tsx',
        ],
    ],

];
