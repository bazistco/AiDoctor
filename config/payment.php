<?php


return [
    'saman' => [
        'terminal_id' => env('SAMAN_TERMINAL_ID', '15768735'),
        'token_expiry_min' => (int)env('SAMAN_TOKEN_EXPIRY_MIN', 20),
        'timeout' => (int)env('SAMAN_TIMEOUT', 30),

        // URLها — مستند فنی نسخه ۳.۶
        'token_url' => env('SAMAN_TOKEN_URL',
            'https://sep.shaparak.ir/onlinepg/onlinepg'),
        'payment_url' => env('SAMAN_PAYMENT_URL',
            'https://sep.shaparak.ir/onlinepg/onlinepg?token='),
        'verify_url' => env('SAMAN_VERIFY_URL',
            'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/VerifyTransaction'),
        'reverse_url' => env('SAMAN_REVERSE_URL',
            'https://sep.shaparak.ir/verifyTxnRandomSessionkey/ipg/ReverseTransaction'),
    ],
];
