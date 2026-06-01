<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],


    // También podés permitir múltiples orígenes:
    'allowed_origins' => ['http://localhost:5173', 'http://192.168.8.16:9050'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
