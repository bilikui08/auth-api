<?php

return [

    'passport' => [
        'password_client_id' => env('PASSPORT_PASSWORD_CLIENT_ID'),
        'password_client_secret' => env('PASSPORT_PASSWORD_CLIENT_SECRET'),
    ],

    // Google reCAPTCHA v2 ("No soy un robot") en el login y en "olvidé mi contraseña". Sin RECAPTCHA_SECRET queda apagado
    // (desarrollo y tests); en producción hay que cargarlo. RECAPTCHA_HOSTNAMES (separados por coma) limita desde qué sitios se
    // aceptan: la misma clave puede estar en otros sitios (por ejemplo un formulario de contacto) y su captcha no debe servir acá.
    'recaptcha' => [
        'secret' => env('RECAPTCHA_SECRET'),
        'hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env('RECAPTCHA_HOSTNAMES', ''))))),
        'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

];
