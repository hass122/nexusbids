## Idempotency

`nb_payment_mark_paid()` MUST be idempotent. It should:
- Re-check current `payment_status` inside a transaction
- Skip if already `paid`
- Only then update `payments`, `orders`, `winners`, and insert `payment_history`

## Environment variables

Set these in Apache/nginx (not committed to git):

    JAZZCASH_MERCHANT_ID=...
    JAZZCASH_PASSWORD=...
    JAZZCASH_INTEGRITY_SALT=...
    EASYPAISA_STORE_ID=...
    EASYPAISA_HASH_KEY=...
    PAYMENT_MODE=sandbox   # or "live"

Then reference them inside `config/payment-gateways.php` with `getenv(...)`.