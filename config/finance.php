<?php

/*
 * Finance (CLAUDE-PLATFORM-MODULES §12). Only the sandbox provider exists
 * until a real bank/merchant contract and credentials are provided — the
 * sandbox never moves real money and every screen using it says so.
 */
return [
    'currency' => 'GEL',

    'providers' => [
        'sandbox' => [
            // Allows the "simulate payment" checkout screen. Keep false on any
            // environment where real families could see the finance module.
            'enabled' => (bool) env('FINANCE_SANDBOX_ENABLED', env('APP_ENV') !== 'production'),
            // Webhook signing secret. Derived from APP_KEY when not set so no
            // placeholder secret ever ships in config.
            'secret' => env('FINANCE_SANDBOX_WEBHOOK_SECRET') ?: hash('sha256', 'finance-sandbox|'.env('APP_KEY')),
        ],
    ],
];
