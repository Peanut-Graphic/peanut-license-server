# License server PR #98 patch readiness — October 10, 2026

Decision: code is prepared for review; production release is NOT yet cleared. No production records, options, plugin files, or customer ownership were modified. No customer claim email was sent.

## Production evidence

Read-only SSH/WP-CLI checks on peanutgraphic.com on October 10, 2026, approximately 13:22–13:24 America/New_York:

- License server active at version 1.5.0. Grep found neither `validate_request` in the validator nor `user_owns_license` in the WooCommerce integration: inspected deployed files do not contain PR #98's guards. A version header alone does not identify deployed code.
- WooCommerce installed at 11.1.0 but inactive. Portal ownership exposure is dormant here while it remains inactive; other deployments have not been inventoried.
- 15 active guest licenses; 13 account/guest email join rows (not necessarily 13 unique customers). Zero account/email rows matching licenses owned by another user. This is a snapshot, not proof that abuse never occurred.
- Restrictions table exists with zero rows. Therefore no current stored IP/domain/hardware restrictions in this deployment can be newly enforced by PR #98. Recheck immediately before deployment.
- The documented email joins initially failed with MySQL error 1267, incompatible utf8mb4 collations. Repeating the comparison with explicit `COLLATE utf8mb4_unicode_ci` on both operands succeeded. No record was modified. Empty restriction sums returned NULL; row count was zero.

## Client inventory (fetched origin/main)

| Client | Activation/revalidation | Identity | Compatibility risk |
| --- | --- | --- | --- |
| Booker 1.8.0 | Activation POST validate; ordinary refresh GET status | home_url; no fingerprint | Hardware locks reject activation. Status refresh does not enforce restrictions. Failed activation marks status invalid. |
| Suite 4.3.2 | POST validate for activation and periodic refresh | home_url; no fingerprint | Hardware/IP/domain rejection is an explicit invalid/free response; transport grace does not make a restriction denial safe. Self-hosted server uses offline path. |
| FormFlow Pro 4.2.2 | Activation POST validate; routine GET status | home_url; no fingerprint | Activation rejects hardware locks; status does not enforce them. |
| Server SDK 1.0.0 | Same request structure as Booker | home_url; no fingerprint | Same activation limitation and key-in-GET transport. |
| FormFlow Lite / FormFlow Core | No matching direct license activation client found in inspected PHP | Not applicable | Core supplies shared update verification; Lite is unlicensed. Do not infer entitlement enforcement from shared update code. |
| Peanut Connect / Peanut Festival / Shield | No direct validate/SDK client found in inspected PHP | Not applicable | Connect has product update endpoint use; inspect signed package/update behavior independently. |

PR #98's statement that Booker periodically uses validate is not borne out by the inspected current SDK: `check_status()` calls status. Status and update routes remain read-only and are not installation-authorization checks.

## Prepared corrections

Server patch 1.5.1, Booker 1.8.1, Suite 4.3.3 and FormFlow 4.2.3 metadata are prepared on review branches. An additive `peanut_license_hardware_id` filter allows an administrator to supply the exact existing fingerprint for activation requests. Its default is empty; arbitrary regenerated IDs cannot repair previously stored locks. No ownership fallback, auto-claim, restriction bypass, or customer-data migration is introduced.

Booker/SDK GET status/check/info, Suite check/info, FormFlow status/check now send license keys in `X-Peanut-License-Key`. Header-capable server must ship first. FormFlow's legacy `get_download_url()` still puts the key in a package URL; converting downloads needs signed-token authorization or a narrowly scoped WordPress download hook. It is deliberately not “fixed” by simply stripping authorization. Existing returned package URLs and package-verification gates are preserved.

## Guest claim assessment

Code inspection and the existing ownership regression suite cover: only user_id authorizes portal/deactivation, purchase-inbox delivery, uniform request response, nonce checking, per-account throttling, HMAC binding to account/email/exact IDs/expiry, expiry and wrong-user rejection, and conditional guest-only updates. Owned licenses cannot be claimed merely through a matching email. Sequential conditional updates prevent ownership overwrite but do not make a multi-license claim transactional; partial success can occur during a race. wp_mail's boolean is ignored, so a successful-looking request is not proof of email delivery.

Real WordPress/WooCommerce and real mail-path behavior remain unverified. WooCommerce is inactive in production and must not be enabled just to test. Use a disposable staging clone with synthetic licenses and a mail sink to verify checkout ownership, login/redirect retention of claim parameters, receipt, intended-account claim, wrong-account rejection, expiration, tampering, replay, race behavior, capability/nonce checks, and portal download tokens. Do not test claims against customer records.

## Safe rollout order and release gates

1. Repeat production counts on every license-server deployment. Preserve evidence without keys, email addresses, fingerprints or customer URLs. Check table presence, corrupt JSON, populated values including rows whose enforce flags are false, actual egress IP, and home_url for restricted installations. A populated hardware row is a stop gate until the exact existing fingerprint can be supplied and tested.
2. Pass a real WordPress/WooCommerce staging claim and activation matrix: unrestricted new/existing activation; allowed/denied IP/domain; matching/missing/mismatched hardware; corrupt data; DB read error; absent restrictions table. Confirm explicit denial stays denied and legitimate activations retain features.
3. Release the server 1.5.1 ownership and restriction fixes with header support. On the inspected production deployment no client-first restriction migration is needed because there are zero restriction rows. Never revert to insecure email ownership to restore portal access.
4. Ship client patches after header support is deployed. For any other deployment with hardware locks, configure and test the fingerprint-capable client BEFORE that server enforces restrictions, or arrange an approved corrected binding. This does not authorize changing customer restrictions.
5. Enable the customer portal only after claim mail/redirect behavior passes. Provide the guest-claim recovery instructions; 15 active guest licenses need verified claiming for account access. Existing installation activation is independent of account claiming.
6. Monitor restriction denials, activation failures, free-tier transitions, claim-mail failures, and support reports after server deployment and each client rollout. Pause client rollout on regressions. Roll forward with a targeted fix while retaining ownership guards; a full pre-#98 rollback restores the security defect.
7. Produce release zips from clean merged commits with consistent patch metadata and the existing signing/build pipeline; verify archive contents and update delivery on staging. No release, zip publication or production deployment occurred during this assessment.

## Validation

Server locked dependencies installed in the isolated worktree; the full mock-WordPress suite passes: 359 tests, 8041 assertions. An initial run used the canonical checkout's autoload map, then an incomplete dependency tree; both environment problems were corrected before the passing run. These tests still do not establish real WooCommerce integration.

Captured-request tests validate SDK/Booker header transport, absence of raw keys in GET URLs, exact fingerprint forwarding, empty/non-string defaults, and retained site identity. Service capture checks cover Suite/FormFlow fingerprint forwarding and FormFlow status headers. PHP syntax and diff whitespace checks run on changed PHP files. Real WordPress, real WooCommerce, full client product suites and packaged release installation remain release gates.
