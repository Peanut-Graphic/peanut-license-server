# HUB read-only entitlement projection

This source connection does not replace WordPress license administration. Provisioning records an operator-confirmed source account label, HUB agency ID and explicit allowlist of existing license IDs. No email matching, activation, customer lookup, signing-key rotation or Firebase write is performed. The source account label is a stable integration binding assigned by the operator, not a claim that the plugin has a separate customer-account domain model.

Provision locally as a trusted WordPress operator:

```
wp peanut-license projection provision <source-account> <hub-agency-id> <license-id,license-id>
wp peanut-license projection provision <source-account> <hub-agency-id> <license-id,license-id> --rotate
wp peanut-license projection revoke <source-account>
```

The random 256-bit token is printed once. Only its SHA-256 hash and account index are saved in non-autoloaded WordPress options. Rotation must use the identical agency/allowlist and invalidates the old token. Revoke disables reads without changing entitlements. The caller cannot expand a binding through request parameters. Rebinding ownership requires a separate reviewed operator procedure; it is deliberately absent from this initial endpoint. A crashed provisioner can leave an account-specific `_lock` option: inspect whether the process remains active and verify the account record before manually clearing only that lock; never bulk-delete projection options.

Read `GET /wp-json/peanut-projection/v1/entitlements` over HTTPS with `Authorization: Bearer <token>`. Consumers must use a fixed HTTPS source, keep credentials server-side, reject redirects, and never send tokens in URLs. Only `cursor` and `per_page` (1–100, default 25) are accepted. A numeric next_cursor pages after the last returned entitlement ID; null ends the page sequence. Up to 1,000 explicit license IDs are supported per principal. Each principal is limited to 60 reads per minute through the existing source limiter. WordPress does not authenticate the projection token as a user, so the token cannot satisfy manage_options for administrative mutation routes.

Response allowlist: schema_version=1, source_account_id, hub_agency_id, observed_at (UTC), entitlements (entitlement_id, product_id, tier, status, expires_at UTC/null, activation_limit, activation_count), next_cursor. Product IDs are stable source product identifiers, not guessed slugs. Raw keys, customer identity/billing, signing secrets, vault payloads and activation site URLs are neither selected nor returned. Explicitly authorized site references are deferred until an actual site-binding contract exists. Source status is reported as stored; readers must keep status, expiration and observation freshness distinct.

Malformed source records or database failure return a generic 503 without a partial result or database details. Unauthorized/revoked credentials return 401, invalid pagination or scope overrides 400, throttling 429. Cache-Control is private,no-store. The consumer must retain its last valid observation and label it stale on failure; it must not revoke customer entitlements or call validation/activation while polling.

Verification: seven scoped projection unit tests plus all existing mock Unit/Integration/Property suites pass (366 tests, 8,146 assertions) against the tracked dependency lock. PHP runtime declarations pass. A real-WordPress contract test is added for REST registration, two-principal isolation, mutation rejection, rotation and revocation; local real-WordPress infrastructure was unavailable, so CI qualification remains required. No live principals, deployment, HUB polling adapter or signing/database recovery drill is claimed here.
