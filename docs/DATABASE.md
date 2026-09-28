# Database Schema Documentation

## Overview

Peanut License Server uses a multi-table database schema to manage licenses, activations, logging, and related functionality.

## Table Prefix

All tables use the WordPress table prefix (typically `wp_`) followed by `peanut_`.

## Entity Relationship Diagram

```
┌─────────────────┐       ┌──────────────────┐
│ peanut_licenses │───1:N─│peanut_activations│
└────────┬────────┘       └──────────────────┘
         │
         │1:N
         ▼
┌─────────────────────┐
│peanut_validation_log│
└─────────────────────┘

┌─────────────────┐       ┌────────────────────┐
│ peanut_bundles  │───N:M─│peanut_bundle_produc│
└────────┬────────┘       └────────────────────┘
         │
         │N:M
         ▼
┌─────────────────────┐
│peanut_license_bundle│
└─────────────────────┘

┌──────────────────┐       ┌─────────────────┐
│ peanut_affiliates│───1:N─│peanut_referrals │
└────────┬─────────┘       └─────────────────┘
         │1:N
         ▼
┌─────────────────┐
│ peanut_payouts  │
└─────────────────┘
```

---

## Core Tables

### peanut_licenses

Primary table for license keys and their metadata.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `license_key` | VARCHAR(128) | Key encrypted at rest (`plk1:` + base64 XChaCha20-Poly1305) when `PEANUT_LICENSE_KEY_SECRET` is set; plaintext otherwise. Never queried. |
| `license_key_hash` | VARCHAR(64) | Lookup hash of the normalized key: HMAC-SHA256 with the vault secret, legacy SHA-256 without it (or before the sweep) |
| `order_id` | BIGINT UNSIGNED | WooCommerce order ID (nullable) |
| `subscription_id` | BIGINT UNSIGNED | WooCommerce subscription ID (nullable) |
| `user_id` | BIGINT UNSIGNED | WordPress user ID (nullable) |
| `customer_email` | VARCHAR(255) | Customer email address |
| `customer_name` | VARCHAR(255) | Customer display name |
| `product_id` | BIGINT UNSIGNED | Associated product ID |
| `tier` | ENUM | 'free', 'pro', 'agency' |
| `status` | ENUM | 'active', 'expired', 'suspended', 'revoked' |
| `max_activations` | INT UNSIGNED | Maximum allowed site activations |
| `created_at` | DATETIME | Creation timestamp |
| `updated_at` | DATETIME | Last update timestamp |
| `expires_at` | DATETIME | Expiration date (nullable for lifetime) |

**Indexes:**
- `unique_license_key_hash` (license_key_hash, UNIQUE; schema 1.7.0)
- `idx_customer_email` (customer_email)
- `idx_status` (status)
- `idx_user_id` (user_id)
- `idx_order_id` (order_id)

---

### peanut_activations

Tracks sites where licenses are activated.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `license_id` | BIGINT UNSIGNED | FK to licenses |
| `site_url` | VARCHAR(255) | Full site URL |
| `site_name` | VARCHAR(255) | Site title |
| `site_hash` | VARCHAR(64) | MD5 hash of normalized URL |
| `ip_address` | VARCHAR(45) | Activation IP |
| `plugin_version` | VARCHAR(20) | Active plugin version |
| `wp_version` | VARCHAR(20) | WordPress version |
| `php_version` | VARCHAR(20) | PHP version |
| `is_multisite` | TINYINT(1) | Is WordPress multisite |
| `active_plugins` | INT | Count of active plugins |
| `health_status` | ENUM | 'healthy', 'warning', 'critical', 'offline' |
| `health_errors` | TEXT | JSON array of errors |
| `activated_at` | DATETIME | Activation timestamp |
| `last_checked` | DATETIME | Last health check |
| `deactivated_at` | DATETIME | Deactivation timestamp |
| `is_active` | TINYINT(1) | Currently active |

**Indexes:**
- `unique_activation` (license_id, site_hash)
- `idx_license_id` (license_id)
- `idx_site_hash` (site_hash)
- `idx_is_active` (is_active)
- `idx_health_status` (health_status)

---

## Logging Tables

### peanut_validation_log

Audit log of all license validation attempts.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `license_key_partial` | VARCHAR(32) | Masked key (XXXX-****-****-XXXX) |
| `license_key_hash` | VARCHAR(64) | Full key hash |
| `site_url` | VARCHAR(255) | Requesting site |
| `ip_address` | VARCHAR(45) | Request IP |
| `user_agent` | VARCHAR(255) | Client user agent |
| `action` | VARCHAR(32) | 'validate', 'activate', 'deactivate' |
| `status` | ENUM | 'success', 'failed' |
| `error_code` | VARCHAR(64) | Error code if failed |
| `error_message` | VARCHAR(255) | Human-readable error |
| `request_data` | TEXT | JSON request payload |
| `created_at` | DATETIME | Request timestamp |

**Indexes:**
- `idx_ip_address` (ip_address)
- `idx_license_key_hash` (license_key_hash)
- `idx_status` (status)
- `idx_created_at` (created_at)
- `idx_ip_status_date` (ip_address, status, created_at)

---

### peanut_update_logs

Tracks plugin update checks and downloads.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `license_id` | BIGINT UNSIGNED | Associated license |
| `site_url` | VARCHAR(255) | Requesting site |
| `plugin_version` | VARCHAR(20) | Current version |
| `new_version` | VARCHAR(20) | Available version |
| `action` | ENUM | 'check', 'download', 'install' |
| `ip_address` | VARCHAR(45) | Request IP |
| `created_at` | DATETIME | Timestamp |

---

### peanut_audit_trail

Complete audit trail of all admin actions.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `event_type` | VARCHAR(64) | Event category |
| `user_id` | BIGINT UNSIGNED | Admin user ID |
| `user_email` | VARCHAR(255) | Admin email |
| `user_name` | VARCHAR(255) | Admin display name |
| `license_id` | BIGINT UNSIGNED | Affected license |
| `object_type` | VARCHAR(32) | 'license', 'activation', etc. |
| `object_id` | BIGINT UNSIGNED | Object ID |
| `action` | VARCHAR(64) | Specific action |
| `old_value` | LONGTEXT | Previous state (JSON) |
| `new_value` | LONGTEXT | New state (JSON) |
| `changes` | LONGTEXT | Diff (JSON) |
| `ip_address` | VARCHAR(45) | Admin IP |
| `user_agent` | VARCHAR(255) | Admin browser |
| `context` | TEXT | Additional context |
| `created_at` | DATETIME | Timestamp |

---

## Bundle System Tables

### peanut_bundles

Product bundle definitions.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `name` | VARCHAR(255) | Bundle name |
| `slug` | VARCHAR(255) | Unique slug |
| `description` | TEXT | Bundle description |
| `tier` | ENUM | 'free', 'pro', 'agency' |
| `max_activations` | INT UNSIGNED | Activations for bundle |
| `is_active` | TINYINT(1) | Bundle available |
| `created_at` | DATETIME | Creation date |
| `updated_at` | DATETIME | Last update |

### peanut_bundle_products

Products included in each bundle.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `bundle_id` | BIGINT UNSIGNED | FK to bundles |
| `product_slug` | VARCHAR(255) | Product identifier |
| `product_name` | VARCHAR(255) | Display name |

### peanut_license_bundles

Links licenses to bundles.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `license_id` | BIGINT UNSIGNED | FK to licenses |
| `bundle_id` | BIGINT UNSIGNED | FK to bundles |
| `created_at` | DATETIME | Assignment date |

---

## Affiliate System Tables

### peanut_affiliates

Affiliate partner records.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `user_id` | BIGINT UNSIGNED | WordPress user (optional) |
| `name` | VARCHAR(255) | Affiliate name |
| `email` | VARCHAR(255) | Contact email |
| `code` | VARCHAR(20) | Unique referral code |
| `commission_type` | ENUM | 'percentage', 'fixed' |
| `commission_rate` | DECIMAL(10,2) | Rate (% or $) |
| `payment_email` | VARCHAR(255) | PayPal email |
| `payment_method` | VARCHAR(50) | 'paypal', 'bank' |
| `is_active` | TINYINT(1) | Active status |
| `created_at` | DATETIME | Registration date |
| `updated_at` | DATETIME | Last update |

### peanut_referrals

Individual referral tracking.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `affiliate_id` | BIGINT UNSIGNED | FK to affiliates |
| `order_id` | BIGINT UNSIGNED | WooCommerce order |
| `order_total` | DECIMAL(10,2) | Order amount |
| `commission_amount` | DECIMAL(10,2) | Earned commission |
| `visitor_ip` | VARCHAR(45) | Referral IP |
| `referrer_url` | TEXT | Traffic source |
| `landing_page` | TEXT | Entry page |
| `is_paid` | TINYINT(1) | Commission paid |
| `payout_id` | BIGINT UNSIGNED | FK to payouts |
| `created_at` | DATETIME | Referral date |

### peanut_payouts

Affiliate payment records.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `affiliate_id` | BIGINT UNSIGNED | FK to affiliates |
| `amount` | DECIMAL(10,2) | Payout amount |
| `referral_ids` | TEXT | JSON array of referral IDs |
| `status` | ENUM | 'pending', 'processing', 'completed', 'failed' |
| `transaction_id` | VARCHAR(255) | External transaction ID |
| `notes` | TEXT | Admin notes |
| `created_at` | DATETIME | Request date |
| `processed_at` | DATETIME | Processing date |

---

## Security Tables

### peanut_security_restrictions

License-level security settings.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `license_id` | BIGINT UNSIGNED | FK to licenses (unique) |
| `ip_whitelist` | TEXT | JSON array of allowed IPs |
| `allowed_domains` | TEXT | JSON array of domains |
| `hardware_id` | VARCHAR(64) | Locked hardware hash |
| `enforce_ip` | TINYINT(1) | Enforce IP restriction |
| `enforce_domain` | TINYINT(1) | Enforce domain restriction |
| `enforce_hardware` | TINYINT(1) | Enforce hardware lock |
| `created_at` | DATETIME | Creation date |
| `updated_at` | DATETIME | Last update |

---

## GDPR Tables

### peanut_gdpr_requests

GDPR data request tracking.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `email` | VARCHAR(255) | Subject email |
| `request_type` | ENUM | 'export', 'anonymize', 'delete' |
| `status` | ENUM | 'pending', 'processing', 'completed', 'failed' |
| `metadata` | TEXT | Request details (JSON) |
| `result` | TEXT | Processing result |
| `created_at` | DATETIME | Request date |
| `processed_at` | DATETIME | Processing start |
| `completed_at` | DATETIME | Completion date |
| `processed_by` | BIGINT UNSIGNED | Admin user ID |

---

## Webhook Tables

### peanut_webhook_log

Outbound webhook delivery log.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `endpoint_url` | VARCHAR(255) | Webhook URL |
| `event` | VARCHAR(64) | Event type |
| `payload` | TEXT | Request body (JSON) |
| `status_code` | INT | HTTP response code |
| `response` | TEXT | Response body |
| `created_at` | DATETIME | Delivery timestamp |

---

## Maintenance

### Cleanup Queries

```sql
-- Delete old validation logs (> 90 days)
DELETE FROM wp_peanut_validation_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);

-- Delete old webhook logs (> 30 days)
DELETE FROM wp_peanut_webhook_log
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Deactivate stale activations (no check-in for 30 days)
UPDATE wp_peanut_activations
SET is_active = 0, deactivated_at = NOW()
WHERE last_checked < DATE_SUB(NOW(), INTERVAL 30 DAY)
AND is_active = 1;
```

### Index Optimization

Recommended periodic maintenance:

```sql
ANALYZE TABLE wp_peanut_licenses;
ANALYZE TABLE wp_peanut_activations;
ANALYZE TABLE wp_peanut_validation_log;
```
