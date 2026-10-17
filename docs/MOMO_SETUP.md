# MTN MoMo Collection setup (Apetrape)

## Architecture

1. App creates order with `pay_method=momo` (or switches to MoMo after create).
2. App opens MoMo screen; user confirms/edits MTN number (76/78).
3. App calls `POST /mobile/v1/payment/momo/request_pay.php` with `{ order_id, cell }`.
4. API obtains a Collection token, inserts `momo_transactions`, calls `requesttopay`.
5. User approves on their phone; MTN hits `callback.php` and/or the app polls `status.php`.
6. On `SUCCESSFUL`, API inserts `payments` and updates `orders.pay_status`.

Admin: `GET /control/payments/momo_transactions.php` + dashboard page **MoMo Payments**.

## Required `.env` keys

Copy from `.env.example` into the project root `.env`:

```
MOMO_API_USER=
MOMO_API_KEY=
MOMO_SUBSCRIPTION_KEY=
MOMO_TARGET_ENVIRONMENT=mtnswaziland
MOMO_CURRENCY=SZL
MOMO_BASE_URL=https://proxy.momoapi.mtn.com
MOMO_CALLBACK_URL=https://apetrape.com/webapi/mobile/v1/payment/momo/callback.php
MOMO_PAYER_MESSAGE=APE Trape order payment
```

EWSC name mapping: `MOMO_UUID` → `MOMO_API_USER`; use `MOMO_TARGET_ENVIRONMENT` (not the misspelled `MOMO_ENVIROMENT`).

## Portal checklist

1. Create / open a **Collection** API user; copy API user, API key, and subscription key.
2. Set the callback URL to `MOMO_CALLBACK_URL` above.
3. Run `migrations/momo_transactions.sql` once (or rely on auto-create).
4. Grant admin roles **payments** read access (same module as Peach).

## Endpoints

| Method | Path | Auth |
|--------|------|------|
| POST | `/mobile/v1/payment/momo/request_pay.php` | Mobile JWT |
| GET | `/mobile/v1/payment/momo/status.php?order_id=` or `?external_id=` | Mobile JWT |
| POST | `/mobile/v1/payment/momo/callback.php` | Public (MTN) |
| GET | `/control/payments/momo_transactions.php` | Admin JWT + payments read |

## MSISDN rules

Accepted payer numbers (MTN Eswatini): local `76xxxxxxx` / `78xxxxxxx`, or with country code `26876…` / `26878…`. API always sends `268` + 8 local digits to MTN.

## Hourly recovery cron

Missed callbacks are recovered by polling MTN for rows still in `new` / `pending`:

```bash
# Every hour
0 * * * * /usr/bin/php /var/www/apetrape_management_webapi/cron/momo_check_pending.php >> /var/www/apetrape_management_webapi/control/logs/momo_cron.log 2>&1
```

Script: [`cron/momo_check_pending.php`](../cron/momo_check_pending.php)

- Skips rows younger than 2 minutes (live app poll still running)
- Ignores rows older than 7 days
- Uses a file lock so overlapping cron runs are skipped
- On `SUCCESSFUL`, calls the same `recordMomoOrderPayment` path as the callback

## Duplicate payment protection

`recordMomoOrderPayment` will not insert twice when:

1. `payments.transaction_id` already equals the MoMo financial id (or `momo-{reference_id}` fallback)
2. `payments.ref` already equals this MoMo `external_id` for the same `order_id`
3. A concurrent insert races — duplicate key / re-select returns the existing row with `inserted: false`

Callback, mobile `status.php`, and the hourly cron all share this function.
