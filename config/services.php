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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     | The DLT-registered SMS gateway phone sign-up sends OTPs through
     | (App\Services\Sms\TextSmsGateway). India-only — DLT (Distributed Ledger
     | Technology scrubbing) is an Indian telecom-regulatory requirement, so a
     | number outside +91 will never actually deliver through this vendor.
     |
     | `bypass_phone`/`bypass_otp` fix the OTP for one test number so a
     | reviewer always knows the code without reading the SMS — that number
     | still gets a real message through the vendor like anyone else, it
     | just skips the verify-attempt throttle. Leave both blank to disable
     | the bypass entirely (e.g. in production).
     */
    'textsms' => [
        'endpoint' => env('SMS_ENDPOINT', 'http://textsms.thetechmore.in/http-tokenkeyapi.php'),
        'auth_key' => env('SMS_AUTH_KEY'),
        'sender_id' => env('SMS_SENDER_ID', 'AAIBUZ'),
        'route' => env('SMS_ROUTE', '1'),
        'template_id' => env('SMS_TEMPLATE_ID'),
        'ttl_minutes' => env('OTP_TTL_MINUTES', 5),
        'bypass_phone' => env('OTP_BYPASS_PHONE'),
        'bypass_otp' => env('OTP_BYPASS_OTP'),
    ],

];
