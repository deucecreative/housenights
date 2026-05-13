<?php

return [
    'apple' => [
        'pass_type_id' => env('APPLE_WALLET_PASS_TYPE_ID'),
        'team_id' => env('APPLE_WALLET_TEAM_ID'),
        'cert_password' => env('APPLE_WALLET_CERT_PASSWORD'),
        // Either a filesystem path (local/dev) OR a base64-encoded blob (Railway/prod).
        // The base64 form takes precedence when both are set.
        'cert_path' => env('APPLE_WALLET_CERT_PATH'),
        'cert_b64' => env('APPLE_WALLET_CERT_B64'),
        'wwdr_path' => env('APPLE_WALLET_WWDR_PATH'),
        'wwdr_b64' => env('APPLE_WALLET_WWDR_B64'),
    ],

    // Placeholder for Task 10 (Google Wallet)
    'google' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),
        'service_account_path' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_PATH'),
        'service_account_b64' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_B64'),
    ],
];
