<?php

use Spatie\LaravelMobilePass\Actions\Apple\NotifyAppleOfPassUpdateAction;
use Spatie\LaravelMobilePass\Actions\Apple\RegisterDeviceAction;
use Spatie\LaravelMobilePass\Actions\Apple\UnregisterDeviceAction;
use Spatie\LaravelMobilePass\Actions\Google\HandleGoogleCallbackAction;
use Spatie\LaravelMobilePass\Actions\Google\NotifyGoogleOfPassUpdateAction;
use Spatie\LaravelMobilePass\Models\Apple\AppleMobilePassDevice;
use Spatie\LaravelMobilePass\Models\Apple\AppleMobilePassRegistration;
use Spatie\LaravelMobilePass\Models\Google\GoogleMobilePassEvent;
use Spatie\LaravelMobilePass\Models\MobilePass;

return [
    /*
    * Read the "Getting credentials from Apple" section in the documentation
    * to learn how to get these values.
    */
    'apple' => [
        // Bridge to our existing APPLE_WALLET_* env vars so we don't need
        // to duplicate variables on Railway.
        'organization_name' => env('APPLE_WALLET_ORGANIZATION_NAME', 'House Nights'),
        'type_identifier' => env('APPLE_WALLET_PASS_TYPE_ID'),
        'team_identifier' => env('APPLE_WALLET_TEAM_ID'),

        // Inline base64 .p12 contents takes precedence over file path.
        'certificate' => env('APPLE_WALLET_CERT_B64'),
        'certificate_path' => env('APPLE_WALLET_CERT_PATH'),
        'certificate_password' => env('APPLE_WALLET_CERT_PASSWORD'),

        'apple_push_base_url' => 'https://api.push.apple.com/3/device',
        'webservice' => [
            'secret' => env('APPLE_WALLET_WEBSERVICE_SECRET'),
            'host' => env('APPLE_WALLET_WEBSERVICE_HOST'),
        ],
    ],

    /*
    * Read the "Getting credentials from Google" section in the documentation
    * to learn how to get these values.
    */
    'google' => [
        'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),

        // Inline base64 service account JSON takes precedence over file path.
        'service_account_key' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_B64'),
        'service_account_key_path' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_PATH'),

        'origins' => array_filter([
            env('APP_FRONTEND_URL'),
            env('APP_URL'),
        ]),

        'api_base_url' => env(
            'MOBILE_PASS_GOOGLE_API_BASE_URL',
            'https://walletobjects.googleapis.com/walletobjects/v1'
        ),
    ],

    /*
    * The actions perform core tasks offered by this package. You can customize the behaviour
    * by creating your own action class that extend the one that ships with the package.
    */
    'actions' => [
        'handle_google_callback' => HandleGoogleCallbackAction::class,
        'notify_apple_of_pass_update' => NotifyAppleOfPassUpdateAction::class,
        'notify_google_of_pass_update' => NotifyGoogleOfPassUpdateAction::class,
        'register_device' => RegisterDeviceAction::class,
        'unregister_device' => UnregisterDeviceAction::class,
    ],

    /*
    * These are the models used by this package. You can replace them with
    * your own models by extending the ones that ship with the package.
    */
    'models' => [
        'mobile_pass' => MobilePass::class,
        'apple_mobile_pass_registration' => AppleMobilePassRegistration::class,
        'apple_mobile_pass_device' => AppleMobilePassDevice::class,
        'google_mobile_pass_event' => GoogleMobilePassEvent::class,
    ],

    /*
    * Register custom pass builders here. Built-in builders are registered
    * automatically — only add entries for builders you have authored yourself.
    * The array is keyed by the builder's snake_case name.
    */
    'builders' => [
        'apple' => [
            // 'my_custom_apple_pass' => MyCustomApplePassBuilder::class,
        ],
        'google' => [
            // 'my_custom_google_pass' => MyCustomGooglePassBuilder::class,
        ],
    ],

    /*
    * The queue connection and name used for pushing pass updates to the Apple and Google
    * wallet APIs. When the connection is `null`, updates will run synchronously.
    */
    'queue' => [
        'connection' => env('MOBILE_PASS_QUEUE_CONNECTION'),
        'name' => env('MOBILE_PASS_QUEUE_NAME', 'default'),
    ],
];
