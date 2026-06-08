# fossbilling-square

A Square payment gateway module for [FOSSBilling](https://fossbilling.org) supporting one-time payments and recurring subscriptions.

---

## Features

- **One-time payments** via Square Web Payments SDK (card tokenized in browser — no card data touches your server)
- **Recurring subscriptions** using Square subscription plans with STATIC pricing
- **Setup fee support** — charged once on the first invoice, excluded from recurring billing
- **Tax-inclusive recurring pricing** — applies FOSSBilling tax rates to recurring amount
- **Admin UI** — manage plan mappings, browse Square catalog, create static pricing variations, compare subscription statuses
- **Bidirectional subscription sync** — cancel in FOSSBilling → Square updates; Square webhook → FOSSBilling updates
- **Sandbox & Production** environments

---

## Requirements

- FOSSBilling (latest)
- Square Developer account
- Square subscription plan with at least one **STATIC pricing** variation per billing cadence

---

## Installation

### 1. Copy the module

Copy the `Squaremanager/` folder into your FOSSBilling installation:

```
/modules/Squaremanager/
```

### 2. Enable the module

In the admin panel: **System → Extensions → Modules** → activate **Squaremanager**.

The module installer automatically:
- Creates the required database tables (`square_plan_map`, `square_customer`, `square_subscription`)
- Deploys the payment adapter to `library/Payment/Adapter/Square.php`
- Deploys the gateway logo to `public/assets/gateways/square.png`

### 3. Add the payment gateway

**System → Payment Gateways** → add **Square** and configure:

| Field | Description |
|-------|-------------|
| Square Access Token | From Square Developer Dashboard |
| Application ID | From Square Developer Dashboard |
| Location ID | Your Square location ID |
| Environment | Sandbox or Production |
| Webhook Signature Key | From Square Developer Dashboard (after creating webhook) |

### 4. Set up the Square webhook

In your **Square Developer Dashboard → Webhooks**, create a webhook pointing to:

```
https://your-domain.com/api/guest/squaremanager/handle_webhook
```

Subscribe to events: `subscription.updated`, `subscription.created`

Copy the **Signature Key** back into the gateway config field.

> The exact webhook URL is shown in the **Squaremanager admin page** with a one-click copy button.

---

## Subscription Setup

Square's Dashboard forces RELATIVE pricing when catalog items are linked to a plan, which cannot be overridden programmatically. This module requires **STATIC pricing** variations.

### Creating static pricing variations

In the Squaremanager admin (**System → Extensions → Settings → Squaremanager**):

1. Click **＋ Create Static Variation**
2. Select the Square subscription plan
3. Select the FOSSBilling product
4. Choose the cadence and confirm the price
5. Optionally auto-map the variation to the product/period

### Mapping plans to products

After creating static variations, add a mapping under **Product → Square Plan Mappings**:

- Select the FOSSBilling product
- Select the billing period
- Enter the Square variation ID (browse with **Browse Square Plans**)
- Set the environment (sandbox/production)

---

## Admin UI

Access via **System → Extensions → Settings → Squaremanager**

### Sections

**Product → Square Plan Mappings**
- Add, edit, delete mappings
- Browse live Square catalog to find variation IDs
- Export/import mappings as JSON

**Subscriptions**
- Side-by-side comparison of Square status vs FOSSBilling status
- Mismatch highlighting
- Per-row: Sync from Square, Cancel in Square, Delete local record
- Sync All — pulls current status from Square for all active subscriptions

**Reference URLs**
- Webhook URL with one-click copy
- IPN Callback URL with one-click copy

**Diagnostics**
- Configuration validation
- Live API ping
- Table row counts

---

## How Payments Work

### One-time payments
1. `getHtml()` renders the Square Web Payments SDK card form
2. Customer enters card details — Square returns a secure token
3. Token is submitted to `Api/Guest.php` → `processTransaction()`
4. Adapter charges the card via Square Payments API
5. Invoice is marked paid in FOSSBilling

### Subscription payments
1. Same tokenization flow as above
2. Square customer and card-on-file are created/reused
3. Square subscription is created (start date = +1 billing period to avoid double-charging)
4. Initial invoice amount is charged immediately (includes setup fee if applicable)
5. Recurring amount = product's recurring price + tax (setup fee excluded)
6. Records written to `square_subscription` and FOSSBilling's native `subscription` table

---

## Supported Billing Periods

| FOSSBilling | Square Cadence |
|-------------|---------------|
| 1D | DAILY |
| 1W | WEEKLY |
| 2W | EVERY_TWO_WEEKS |
| 1M | MONTHLY |
| 2M | EVERY_TWO_MONTHS |
| 3M | QUARTERLY |
| 4M | EVERY_FOUR_MONTHS |
| 6M | EVERY_SIX_MONTHS |
| 1Y | ANNUAL |
| 2Y | EVERY_TWO_YEARS |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `square_plan_map` | Maps FOSSBilling product + billing period → Square variation ID |
| `square_customer` | Links FOSSBilling client ID → Square customer ID |
| `square_subscription` | Tracks active/historical subscriptions |

FOSSBilling's native `subscription` table is also written to on subscription creation so subscriptions appear in the standard client/admin subscription views.

---

## License

Apache 2.0

---

## Author

Louis Gutenschwager
https://github.com/lcslouis
