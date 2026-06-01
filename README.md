# Square Integration for FOSSBilling

A full-featured Square integration for FOSSBilling, providing:

- One-time payments
- Subscription billing
- Per-period setup fees
- SKU-based product mapping
- Square catalog export
- Admin UI for mapping and auto-sync

---

## Overview

This project integrates Square with FOSSBilling using a hybrid architecture.

The integration is split into two components which work together to provide full functionality.

### Payment Adapter

The payment adapter is responsible for runtime payment handling and is installed into:

/library/Payment/Adapter/

It handles:

- Payment form rendering
- Secure card tokenization
- One-time payments
- Subscription creation
- Setup fee processing
- Webhook handling

---

### Admin Module (Square Manager)

The admin module is installed into:

/modules/Squaremanager/

It provides an administrative interface for:

- Exporting products to Square-compatible CSV
- Managing SKU mappings
- Automatically discovering Square variation IDs
- Debugging subscription mappings

---

Both components are required for full functionality.

---

## Features

### Payments

- One-time payments using Square Web Payments SDK
- Secure card tokenization (no card data handled directly)
- Full webhook support for payment validation

---

### Subscriptions

Supports all standard FOSSBilling billing periods:

- Weekly
- Monthly
- Every 3 Months
- Every 6 Months
- Every Year
- Every 2 Years
- Every 3 Years

---

### Setup Fees

- Setup fees are handled as separate one-time charges
- Only applied when greater than 0.00
- Supports different setup fees per billing cycle

---

### SKU-Based Mapping

Each billing option generates a deterministic SKU.

Example:

hosting-basic-monthly
hosting-basic-monthly-setup
hosting-basic-3month
hosting-basic-yearly

This ensures:

- Exact matching with Square catalog
- No reliance on product name parsing
- Clean, predictable mapping

---

## Admin UI (Square Manager)

The Square Manager interface is available in the admin panel:

/admin/squaremanager

---

### Features

The admin interface provides full control over the integration:

- Export products to Square-compatible CSV
- View all product and billing combinations
- Display generated SKUs
- View and edit Square variation IDs
- Auto-sync variation mappings from Square
- Per-row "Detect" for targeted lookup
- Highlight missing mappings
- Search and filter results
- Debug mode for troubleshooting

---

### Mapping Table

Each row represents:

- Product
- Billing period
- Generated SKU
- Square variation ID

Example:

hosting-basic | monthly | hosting-basic-monthly | <variation_id>

---

### Auto Sync

Auto Sync attempts to automatically map:

FOSSBilling product + billing → SKU → Square variation

This uses the same logic as the payment adapter, ensuring consistency between:

- Admin mapping
- Live subscription creation

---

## Installation

⚠️ This extension installs into TWO locations.

Both steps are required.

---

### 1. Install Module

Copy:

module/Squaremanager/

to:

/modules/Squaremanager/

Then enable the module in the admin panel.

---

### 2. Install Payment Adapter

Copy:

adapter/Square.php
adapter/square-checkout.js

to:

/library/Payment/Adapter/


---

### 3. Install Gateway Logo

Copy:

module/Squaremanager/installer_files/public/assets/gateways/square.png

to:

/public/assets/gateways/square.png

---

### 4. Configure Gateway

In the admin panel:

System → Payments → Square

Configure:

- Application ID
- Access Token
- Location ID
- Webhook Signature Key

---

### 5. Enable Gateway

After configuration:

- Enable the Square payment gateway
- It will then be available during checkout

---

## Export to Square

The export tool generates a CSV compatible with Square catalog import.

Access:

/admin/squaremanager → Export Products

---

### Export Includes

- All products
- All billing periods
- Setup fee variations (if defined)
- Correct SKU format

---

### SKU Format

product-slug-billing
product-slug-billing-setup

Example:

hosting-basic-monthly
hosting-basic-monthly-setup


This ensures direct matching with Square catalog items.

---

## Mapping System

The integration uses a deterministic mapping system to connect FOSSBilling products with Square subscription plan variations.

---

### Mapping Table

Mappings are stored in the database table:


square_product_plan_map


Each record contains:

- product_id
- billing_key
- square_sku
- square_plan_variation_id

---

### How Mapping Works

1. A SKU is generated:

product_slug + billing_key

2. The system attempts to locate a matching Square item variation:
- Exact SKU match is required

3. The corresponding Square subscription plan variation is resolved

4. The result is stored locally for future use

---

### Example

Product: hosting-basic
Billing: monthly
Generated SKU:
hosting-basic-monthly


This SKU is used to locate the corresponding Square catalog entry.

---

### Why This Design

This approach avoids:

- Product name mismatches
- Manual mapping complexity
- Inconsistent subscription linking

And ensures:

- Reliable automation
- Scalable product handling
- Exact matching with Square catalog

---

## Design Decisions

---

### Deterministic SKU Strategy

FOSSBilling does not provide detailed billing structure to external systems.

To compensate, this integration derives a consistent identifier:

slug + billing_key

This allows:

- Exact SKU matching
- Cross-system compatibility
- Predictable behavior

---

### Setup Fee Handling

Square subscriptions do not support embedded setup fees.

Solution:

1. Charge setup fee as one-time payment
2. Create subscription separately

This ensures:

- Accurate billing
- Compatibility with Square API
- Transparent transaction flow

---

### Local Mapping Cache

Mappings are stored locally to:

- Avoid repeated catalog scans
- Improve performance
- Allow manual overrides

Admin users can:

- Edit mappings directly
- Re-sync automatically at any time

---

## Workflow

Recommended setup process:

1. Create products in FOSSBilling
2. Set product slugs
3. Export products using Square Manager
4. Import products into Square
5. Create subscription plans in Square
6. Run Auto Sync in Square Manager
7. Verify mappings
8. Begin accepting payments

---

## Developer Notes

---

### Billing Keys

weekly
monthly
3month
6month
yearly
2year
3year

---

### FOSSBilling Period Mapping

| Billing Key | Period |
|------------|--------|
| weekly     | 1W |
| monthly    | 1M |
| 3month     | 3M |
| 6month     | 6M |
| yearly     | 1Y |
| 2year      | 2Y |
| 3year      | 3Y |

---

## Limitations

- Square subscription plan variations must already exist
- RELATIVE pricing plans may require additional handling
- Product slugs must be defined in FOSSBilling
- Setup fees are processed as separate transactions

---

## License

Apache 2.0

---

## Contribution

Contributions are welcome.

Please open issues or submit pull requests on GitHub.

---

## Author

Louis Gutenschwager  
https://github.com/lcslouis