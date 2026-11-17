<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // دامین‌های اصلی و عملیاتی
        'https://mediraai.com',
        'https://app.mediraai.com',
        'https://chat.mediraai.com',
        'https://ai.mediraai.com',
        'https://socket.mediraai.com',

        // دامین‌های محیط توسعه (Local)
        'http://localhost:5173',  // پورت پیش‌فرض Vite
        'http://127.0.0.1:5173',
        'http://localhost:3000',  // اگر از React/Next معمولی استفاده می‌کنید
        'http://localhost',       // برای مواردی که پورت مشخص نیست
    ],


    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
