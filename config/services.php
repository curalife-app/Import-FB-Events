<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'shopify' => [
        'us' => [
            'host' => env('SHOPIFY_US_HOST'),
            'token' => env('SHOPIFY_US_TOKEN'),
            'api_key' => env('SHOPIFY_US_API_KEY'),
            'api_secret' =>  env('SHOPIFY_US_API_SECRET'),
            'import_ident_key' => env('SHOPIFY_US_IMPORT_KEY'),
        ],
        'global' => [
            'host' => env('SHOPIFY_GLOBAL_HOST'),
            'token' => env('SHOPIFY_GLOBAL_TOKEN'),
            'api_key' => env('SHOPIFY_GLOBAL_API_KEY'),
            'api_secret' => env('SHOPIFY_GLOBAL_API_SECRET'),
            'import_ident_key' => env('SHOPIFY_GLOBAL_IMPORT_KEY'),
        ]
    ],

    'facebook' => [
        'pixel_id' => env('FB_PIXEL_ID'),
        'access_token' => env('FB_ACCESS_TOKEN'),
        'test_event_code' => env('FB_TEST_EVENT_CODE'),
    ]

];
