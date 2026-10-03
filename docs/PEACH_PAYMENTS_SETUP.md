# Peach Payments setup (Apetrape)

## Architecture

1. App creates order with `pay_method=peach`
2. App calls `POST /mobile/v1/payment/create_checkout.php`
3. API authenticates with Peach OAuth and creates Checkout V2
4. App opens HTTPS embed page in WebView (`embed.php`)
5. Peach sends webhook to `peach_webhook.php` → inserts `payments` + sets `pay_status`
6. App polls `GET /mobile/v1/payment/status.php?order_id=`

Offline methods (EFT / MoMo / e-mali / eWallet) remain available as fallbacks.

## Dashboard checklist

1. Log in to [Peach Dashboard](https://dashboard.peachpayments.com) (sandbox: sandbox-dashboard).
2. Create / open your **Checkout** channel; copy:
   - Client ID, Client Secret, Merchant ID, Entity ID
3. **Allowlist domains**: add the origin that serves `embed.php`
   - Example: `https://apetrape.com`
4. **Webhooks**: set URL to  
   `https://apetrape.com/webapi/mobile/v1/payment/peach_webhook.php`  
   Enable **webhook signing** and copy the secret.
5. Paste values into API `.env` (see `.env.peach.example`).

## Required `.env` keys

```
PEACH_CLIENT_ID=
PEACH_CLIENT_SECRET=
PEACH_MERCHANT_ID=
PEACH_ENTITY_ID=
PEACH_AUTH_URL=https://sandbox-dashboard.peachpayments.com
PEACH_CHECKOUT_URL=https://testsecure.peachpayments.com
PEACH_CURRENCY=ZAR
PEACH_ORIGIN=https://apetrape.com
PEACH_EMBED_BASE_URL=https://apetrape.com/webapi
PEACH_WEBHOOK_URL=https://apetrape.com/webapi/mobile/v1/payment/peach_webhook.php
PEACH_WEBHOOK_SECRET=
PEACH_WEBHOOK_SKIP_VERIFY=0
```

For live: switch auth/checkout URLs to production hosts and unset skip verify.

## DB

Run `migrations/peach_checkouts.sql` and `migrations/peach_saved_cards.sql` once (or rely on auto-create in `ensurePeachCheckoutsTable` / `ensurePeachSavedCardsTable`).

## Saved cards (tokenisation)

Controlled by `PEACH_TOKENISATION` (default `1`) and `PEACH_REQUIRE_CVV` (default `0`).

1. `create_checkout.php` sends `allowStoringDetails=true`, so Peach shows a "save card" checkbox (customer consent).
2. On a successful payment where the customer ticked it, the webhook (or `status.php` recovery) stores the `registrationId` in `peach_saved_cards` against the order's user. Only the token, brand, last 4 digits and expiry are stored — never the card number.
3. Next checkout, the user's non-expired tokens are sent as `cardTokens`, plus `customer.merchantCustomerId` = user id. Embedded Checkout lists the saved cards.
4. Removing a card inside checkout calls `remove_card.php` via `onRemoveCard`. It only deletes a token owned by the user of an open checkout created in the last 60 minutes.

Ask Peach support to confirm tokenisation / card registrations are enabled on your entity before going live.

## Test plan

| Case | Expected |
|------|----------|
| Peach pay success (sandbox card) | Webhook → `pay_status=paid`; app navigates to order history |
| Cancel / close WebView | Order stays `pending`; offline methods still work |
| Replay webhook | No duplicate `payments` rows (idempotent `transaction_id`) |
| Offline EFT | Same instruction screens as before |
| Pay with "save card" ticked | Row added to `peach_saved_cards` for that user |
| Next checkout for same user | Saved card shown; pays without re-entering card |
| Remove saved card in checkout | Row deleted; card not offered on next checkout |
| Different user | Never sees another user's saved cards |

## Endpoints

| Method | Path | Auth |
|--------|------|------|
| POST | `/mobile/v1/payment/create_checkout.php` | Mobile JWT |
| GET | `/mobile/v1/payment/embed.php?checkoutId=` | Public HTTPS |
| GET | `/mobile/v1/payment/result.php` | Public (WebView result) |
| POST | `/mobile/v1/payment/peach_webhook.php` | Peach HMAC |
| GET | `/mobile/v1/payment/status.php?order_id=` | Mobile JWT |
| POST | `/mobile/v1/payment/remove_card.php` | Open checkout owned by the card's user |

Docs: https://developer.peachpayments.com/docs/checkout-embedded-flutter-tutorial
