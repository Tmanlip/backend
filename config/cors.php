<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://localhost:3000', 'https://gentle-bush-0c3f5a800.3.azurestaticapps.net'],
    'allowed_headers' => ['*'],
    'supports_credentials' => false,
];

