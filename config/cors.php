<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_origins' => ['http://localhost:3000', 
                          'files',
                          'https:gentle-bush-0c3f5a800.3.azurestaticapps.net'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

