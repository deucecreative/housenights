<?php

return [
    'apple' => [
        'pass_type_id' => env('APPLE_WALLET_PASS_TYPE_ID'),
        'team_id' => env('APPLE_WALLET_TEAM_ID'),
        'cert_path' => env('APPLE_WALLET_CERT_PATH'),
        'cert_password' => env('APPLE_WALLET_CERT_PASSWORD'),
        'wwdr_path' => env('APPLE_WALLET_WWDR_PATH'),
    ],

    // Placeholder for Task 10 (Google Wallet)
    'google' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
        'service_account_path' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_PATH'),
    ],
];
