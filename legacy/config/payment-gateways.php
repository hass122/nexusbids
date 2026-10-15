<?php
/*
|--------------------------------------------------------------------------
| Payment Gateway Configuration
|--------------------------------------------------------------------------
|
| Central config for all payment providers.
|
| DO NOT commit real credentials to git.
| Recommended: set environment variables in Apache/xampp or use a
| .env file loaded by your bootstrap.
|
| To activate a gateway: set 'enabled' => true and fill in credentials.
| Leave placeholders as-is until you have real values — the code will
| detect missing credentials and return a clear error instead of
| silently failing.
*/

return [

    /* =====================================================
       GLOBAL
       ===================================================== */
    'mode' => getenv('PAYMENT_MODE') ?: 'sandbox', // sandbox | live
    'currency' => 'PKR',
    'platform_fee_pct' => 5.00,  // % commission NexusBids keeps

    /* =====================================================
       JAZZCASH
       Get credentials from: https://sandbox.jazzcash.com.pk
       (register as merchant, get Merchant ID, Password, Integrity Salt)
       ===================================================== */
    'jazzcash' => [
        'enabled' => false, // set true once credentials are real
        'label' => 'JazzCash',
        'merchant_id' => getenv('JAZZCASH_MERCHANT_ID') ?: 'MC00000',
        'password' => getenv('JAZZCASH_PASSWORD') ?: 'xxxxxx',
        'integrity_salt' => getenv('JAZZCASH_SALT') ?: 'xxxxxx',
        'sandbox_url' => 'https://sandbox.jazzcash.com.pk/ApplicationAPI/API/Payment/DoTransaction',
        'live_url' => 'https://payments.jazzcash.com.pk/ApplicationAPI/API/Payment/DoTransaction',
        'return_url' => '/payments/jazzcash/callback.php',
        'webhook_enabled' => true,
    ],

    /* =====================================================
       EASYPAISA
       Get credentials from: https://easypay.easypaisa.com.pk
       (register as merchant, get Store ID, Hash Key)
       ===================================================== */
    'easypaisa' => [
        'enabled' => false,
        'label' => 'Easypaisa',
        'store_id' => getenv('EASYPAISA_STORE_ID') ?: '00000',
        'hash_key' => getenv('EASYPAISA_HASH_KEY') ?: 'xxxxxxxx',
        'sandbox_url' => 'https://easypaystg.easypaisa.com.pk/easypay/Index.jsf',
        'live_url' => 'https://easypay.easypaisa.com.pk/easypay/Index.jsf',
        'return_url' => '/payments/easypaisa/callback.php',
        'webhook_enabled' => true,
    ],

    /* =====================================================
       BANK TRANSFER (Manual Verification)
       No gateway API — admin verifies the receipt + uploads.
       ===================================================== */
    'bank_transfer' => [
        'enabled' => true, // works out of the box
        'label' => 'Bank Transfer',
        'bank_name' => 'Meezan Bank',
        'account_title' => 'NexusBids Pvt Ltd',
        'account_number' => 'PK00MEZN0000000000000000',
        'iban' => 'PK00MEZN0000000000000000',
        'instructions' => [
            'Transfer the exact amount to the account above.',
            'Keep the deposit slip or screenshot.',
            'Enter your transaction reference (from the slip) on the next step.',
            'Our team verifies within 24 hours.',
        ],
    ],

    /* =====================================================
       CARD (Gateway placeholder)
       For real card processing, integrate a proper provider:
       - PayFast by APG (Pakistan)
       - Stripe (needs Stripe merchant account)
       - 2Checkout
       ===================================================== */
    'card' => [
        'enabled' => false,
        'label' => 'Debit / Credit Card',
        'provider' => 'payfast', // payfast | stripe | 2checkout
        'public_key' => getenv('CARD_PUBLIC_KEY') ?: '',
        'secret_key' => getenv('CARD_SECRET_KEY') ?: '',
        'webhook_secret' => getenv('CARD_WEBHOOK_SECRET') ?: '',
        'return_url' => '/payments/card/callback.php',
    ],

    /* =====================================================
       CASH ON DELIVERY
       Buyer pays cash when the item arrives.
       ===================================================== */
    'cash_on_delivery' => [
        'enabled' => true,
        'label' => 'Cash on Delivery',
        'max_order_value' => 100000, // Rs. — above this, COD is disabled
        'instructions' => [
            'Confirm your order.',
            'Seller ships the item to your address.',
            'Pay the courier in cash when it arrives.',
        ],
    ],

];
?>