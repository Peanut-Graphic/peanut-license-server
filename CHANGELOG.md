# Changelog

All notable changes to Peanut License Server will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-06-16

### Fixed
- **Migration trigger now fires for every request type, not just wp-admin.**
  The DB migration check was hooked on `admin_init`, which only runs when a
  logged-in admin loads wp-admin. On an upgraded, API-only/headless server the
  REST update-check/activation/site-health endpoints, admin-ajax downloads, and
  WP-Cron never triggered it, and auto-update doesn't re-run the activation
  hook — so new code could write columns the DB lacked. The check now runs on
  `plugins_loaded` behind a fast version gate, with peanut-connect-style drift
  detection (an option that claims "current" can no longer trap a broken schema)
  and an idempotent `create_tables()`/dbDelta safety-net covering ALL tables
  (previously only `activations` had any upgrade coverage). Cheap on the hot
  path: a single option read plus one COUNT(*) per tracked column when current.
- **Invalid ENUM value on the validation-logging hot path.** The validation
  logger inserted `status = 'unknown'` into an `ENUM('success','failed')`
  column, which errors under MySQL STRICT mode on every non-success validation.
  The value is now clamped to a valid member (anything that isn't an explicit
  success is recorded as `failed`).
- **Unbounded daily subscription sync.** The scheduled fleet sync selected
  every subscription and looped over all of them with no limit. It now processes
  a capped chunk per run (`SYNC_CHUNK_SIZE`), advancing a persisted cursor
  across runs and wrapping at the end, so it scales as the fleet grows.

### Added
- Schema-drift CI guard (`SchemaDriftGuardTest`): asserts that every column
  written via `$wpdb->insert()/->update()` to a `peanut_*` table exists in that
  table's dbDelta schema (CREATE columns plus migration-added columns).

## [1.3.5] - 2026-06-06

### Added
- PHP backend test + coverage CI gate (`tests` workflow) — the license/billing
  backend previously had no PHP CI; Unit suite (Brain Monkey, no DB) now runs on
  every PR with a coverage floor that ratchets up over time
- Mock-WordPress test bootstrap (`tests/bootstrap.php`, `tests/wp-stubs.php`) so
  the Unit suite runs without the full WP test harness
- Frontend accessibility CI pipeline (axe) with component a11y tests

### Fixed
- Mobile license admin surfaces
- Frontend accessibility lint that never actually ran; associated form labels
  with their controls

### Changed
- Extracted download-token helpers into `includes/download-token-functions.php`
- Synced `.editorconfig` and `.gitignore` with ecosystem standard templates

## [1.3.2] - 2026-01-03

### Added
- Centralized `Peanut_Logger` class for consistent logging across the plugin
- Log levels: DEBUG, INFO, WARNING, ERROR with configurable minimum level
- Sensitive data filtering in logs (passwords, tokens, keys automatically redacted)
- License key masking in log output
- Specialized logging methods: `license()` for license events, `api()` for API requests
- WordPress filter `peanut_license_log_level` to customize minimum log level
- Action hook `peanut_license_log` for custom log handlers

### Changed
- Extracted WooCommerce Customer Portal CSS to separate file (`assets/css/woocommerce-portal.css`)
- Reduced `class-woocommerce-integration.php` from 568 to 346 lines (39% reduction)
- Migrated all `error_log()` calls to use `Peanut_Logger` for consistent formatting
- Improved log context with structured data instead of string concatenation

## [1.3.1] - 2025-12-28

### Fixed
- Fixed translation loading deprecation warning for WordPress 6.7+ (moved to `init` hook)
- Updated text domain loading to follow WordPress best practices

## [1.3.0] - 2025-12-26

### Added
- Modern React SPA frontend built with Vite
- Dashboard with license statistics, quick actions, and recent activity
- Licenses page with full CRUD operations, search, and filters
- Analytics page with validation charts (Recharts), tier distribution, error analysis
- Audit Trail page with searchable action logs
- Webhooks management page with event subscriptions
- Products page for plugin update management
- GDPR Tools page for data export, anonymization, and deletion
- Security page with IP blocking, rate limits, and security event monitoring
- Settings page with API configuration and danger zone actions
- Dark mode toggle (light/dark/system preferences)
- Skeleton loading states for all pages
- Tooltip components with viewport-aware positioning
- Collapsible info banners matching Peanut Suite design
- Danger zone components with confirmation dialogs

### Tech Stack
- React 19 + TypeScript
- Vite 6 for build tooling
- Tailwind CSS 4.0 for styling
- React Query for data fetching
- React Router for navigation
- Recharts for analytics charts
- Lucide for icons

## [1.2.2] - 2025-12-21

### Changed
- Moved inline JavaScript from view files to admin.js for better maintainability
- Moved inline CSS from view files to admin.css for consistency
- Added CSS variables alignment with Peanut Suite design system
- Added License Map, GDPR Tools, Analytics, and Settings page styles to external CSS

### Fixed
- Improved nonce handling for settings page file recheck functionality

## [1.2.1] - 2025-12-21

### Fixed
- Fixed PHP 8.x deprecation warning for `add_submenu_page(null, ...)` by using empty string
- Updated version numbering

## [1.2.0] - 2025-12-20

### Added
- Dashboard page with overview stats and quick actions
- License Map visual tree view
- Info cards with dismissible help content
- GDPR compliance tools (export, anonymize, delete)
- Analytics page with charts and metrics
- Action dropdown menus for license list
- CSS variables for design system consistency

### Changed
- Redesigned admin UI with modern card-based layout
- Improved responsive design for mobile devices
- Enhanced table styling and status badges

## [1.1.0] - 2025-12-15

### Added
- WooCommerce Subscriptions integration
- Automatic license creation on subscription purchase
- Webhook notifications for subscription events
- Batch operations for bulk license management
- Security event logging
- Rate limiting for API protection

### Changed
- Improved license validation performance
- Enhanced error handling and messages

## [1.0.0] - 2025-12-01

### Added
- Initial release
- License key generation and management
- Three license tiers: Free, Pro, Agency
- Site activation tracking
- RESTful API for license validation
- Plugin update server
- Audit trail logging
- Basic analytics
- WooCommerce integration for license purchases
- Client SDK for plugin integration
