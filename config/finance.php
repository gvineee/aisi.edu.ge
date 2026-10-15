<?php

/*
 * Finance (CLAUDE-PLATFORM-MODULES §12). Only the sandbox provider exists
 * until a real bank/merchant contract and credentials are provided — the
 * sandbox never moves real money and every screen using it says so.
 */
return [
    'currency' => 'GEL',

    // Online card payment through a provider. Off until a real bank
    // contract exists: families then pay by transfer/cash and the
    // accountant records the payment by hand (no "pay" button is shown).
    'online_payments' => (bool) env('FINANCE_ONLINE_PAYMENTS', false),

    // Daily automatic invoicing from fee plans (scheduler).
    'auto_generate' => (bool) env('FINANCE_AUTO_GENERATE', true),

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
