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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | CenPOS payment gateway — used by Business Management's "credit/debit
    | card" review screen to void a customer's stored card token on reject.
    | Mirrors legacy's CENPOST_MERCHANT_ID/CENPOS_VERIFYINGPOST/
    | CENPOS_DELETETOKEN_URL constants (defined outside the legacy repo).
    */
    'cenpos' => [
        'merchant_id' => env('CENPOS_MERCHANT_ID'),
        'merchant_secretkey' => env('CENPOS_MERCHANT_SECRETKEY'),
        'verifyingpost_url' => env('CENPOS_VERIFYINGPOST_URL'),
        'deletetoken_url' => env('CENPOS_DELETETOKEN_URL'),
        'verify_ssl' => env('CENPOS_VERIFY_SSL', true),
    ],

    /*
    | Infobip SMS gateway — used by Transactions > Resend Transaction Receipt
    | to text a customer their receipt link. Mirrors legacy's
    | settings::send_sms(), which POSTs to Infobip's Advanced SMS API with a
    | hardcoded production API key. `enabled` defaults OFF so this codebase
    | never sends a real SMS until a deployment deliberately turns it on with
    | real production credentials.
    */
    'infobip' => [
        'enabled' => env('INFOBIP_ENABLED', false),
        'base_url' => env('INFOBIP_BASE_URL', 'https://api.infobip.com'),
        'api_key' => env('INFOBIP_API_KEY'),
        'sender' => env('INFOBIP_SENDER', 'suncash'),
    ],

];
