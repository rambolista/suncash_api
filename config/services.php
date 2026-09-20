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

    // SMS gateway alternative to Infobip — a Bahamas-carrier SOAP endpoint
    // (newcomobile.com), legacy's "aliv" path in settings::send_sms(). Off
    // by default, same reasoning as `infobip` above. Unrelated to the
    // "Aliv" mobile-topup carrier used elsewhere in Kiosk billing.
    'aliv_sms' => [
        'enabled' => env('ALIV_SMS_ENABLED', false),
        'endpoint' => env('ALIV_SMS_ENDPOINT', 'https://portalservice.newcomobile.com/TheListWebService.asmx?wsdl'),
        'token' => env('ALIV_SMS_TOKEN'),
    ],

    // WhatsApp via Infobip — same account/API key as `infobip` above, just
    // a different channel. Business-initiated sends must use a
    // pre-approved template (Meta/WhatsApp policy), so `sender`/
    // `template_name`/`language` have no defaults — they only exist once
    // approved on the Infobip dashboard.
    'whatsapp' => [
        'enabled' => env('WHATSAPP_ENABLED', false),
        'sender' => env('WHATSAPP_SENDER'),
        'template_name' => env('WHATSAPP_TEMPLATE_NAME'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE'),
    ],

    // Tools > Customer Management > View ComplyAdvantage Profile. Legacy's
    // own credentials (COMPLY_API_URL/USERNAME/REALM/PASSWORD) live outside
    // its checked-in source (server-level config not present in this repo),
    // so this stays disabled until real ones are supplied per environment.
    'comply_advantage' => [
        'enabled' => env('COMPLY_ADVANTAGE_ENABLED', false),
        'api_url' => env('COMPLY_ADVANTAGE_API_URL'),
        'username' => env('COMPLY_ADVANTAGE_USERNAME'),
        'realm' => env('COMPLY_ADVANTAGE_REALM'),
        'password' => env('COMPLY_ADVANTAGE_PASSWORD'),
    ],

    // Tools > Customer Management > Reset Pin. Legacy hardcodes a live GET
    // straight to https://prod.mysuncash.com regardless of which
    // environment the admin panel itself runs in — a real footgun, not
    // something to replicate. This reads the target URL from config/env
    // instead (blank/disabled by default) so it can never fire against a
    // real environment until deliberately configured.
    'reset_pin' => [
        'enabled' => env('RESET_PIN_ENABLED', false),
        'url' => env('RESET_PIN_URL'),
    ],

    // Tools > Customer Management > Push Notification. Disabled by default
    // — legacy's cached FCM OAuth bearer token (`push_notif_token`) is
    // refreshed by a process outside this codebase, so there's no real
    // credential to reuse yet. The SMS fallback (via SmsManager) works
    // regardless of this flag.
    'fcm' => [
        'enabled' => env('FCM_ENABLED', false),
        'url' => env('FCM_URL'),
        'server_key' => env('FCM_SERVER_KEY'),
    ],

    // Kiosk > Voucher Pin Tool — decrypts merchant_vouchers/universal_vouchers.pin
    // (legacy VOUCHER_CRYPT_KEY / VOUCHER_IV constants, AES-128-CBC).
    'voucher' => [
        'crypt_key' => env('VOUCHER_CRYPT_KEY'),
        'iv' => env('VOUCHER_IV'),
    ],

];
