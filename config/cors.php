<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['http://localhost:3000', 'https://red-desert-039397a00.2.azurestaticapps.net'],
    'allowed_headers' => ['*'],
    'supports_credentials' => false,
];

