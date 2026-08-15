# Peanut License Server

## Development Toolchain

Frontend tooling uses exact Node 22.22.2 and npm 10.9.7. Run `nvm use` from
the repository root, then `npm ci --legacy-peer-deps` and
`npm run runtime:verify` from `frontend/` before testing or building.

License management, validation, and update server for Peanut Suite WordPress plugins.

## Overview

Peanut License Server is the central backend for the entire Peanut ecosystem. It provides:

- License generation and management
- Site activation/deactivation tracking
- Automatic plugin update distribution
- WooCommerce integration for e-commerce
- Security features and audit trails
- Customer analytics and reporting

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- WooCommerce (optional, for e-commerce features)
- WooCommerce Subscriptions (optional, for recurring licenses)

The installed plugin supports PHP 8.0 and later. Local dependency resolution
and the full mock test suites require PHP 8.1 or later because PHPUnit 10 does
not run on PHP 8.0. Required CI proves the complete tracked PHP tree parses on
exact PHP 8.0, runs the full mock suites on exact PHP 8.1, retains the PHP 8.2
coverage/security lanes, and exercises the real WordPress contract on PHP 8.3.

## Supported Products

- Peanut Suite
- FormFlow Pro
- Peanut Booker

## Installation

1. Upload the `peanut-license-server` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress admin
3. Navigate to **Peanut Licenses** in the admin menu
4. Configure your license tiers and settings

## License Tiers

| Tier | Activations | Features |
|------|-------------|----------|
| Free | 1 | Basic modules |
| Pro | 3 | + Advanced features |
| Agency | 25 | + Monitor, invoicing, white-label |

## REST API Endpoints

The plugin exposes REST API endpoints at `/wp-json/peanut-api/v1/`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/license/validate` | POST | Validate a license key |
| `/license/activate` | POST | Activate license on a site |
| `/license/deactivate` | POST | Deactivate license from a site |
| `/updates/{plugin}/{version}` | GET | Check for plugin updates |

## WooCommerce Integration

When WooCommerce is active, the plugin:

- Automatically generates licenses on order completion
- Manages license status with subscription changes
- Provides a customer portal for license management
- Syncs with WooCommerce Subscriptions for renewals

## Security Features

- Rate limiting on API endpoints
- IP-based validation and blocking
- Domain verification
- Hardware fingerprinting (optional)
- Comprehensive audit logging

## CLI Commands

```bash
# List all licenses
wp peanut-license list

# Generate a new license
wp peanut-license generate --tier=pro --email=customer@example.com

# Validate a license
wp peanut-license validate --key=XXXX-XXXX-XXXX-XXXX

# Revoke a license
wp peanut-license revoke --key=XXXX-XXXX-XXXX-XXXX
```

## File Structure

```
peanut-license-server/
├── peanut-license-server.php    # Main plugin file
├── includes/                     # Core functionality
│   ├── class-license-manager.php
│   ├── class-license-validator.php
│   ├── class-api-endpoints.php
│   ├── class-update-server.php
│   └── ...
├── admin/                        # Admin interface
├── frontend/                     # React SPA admin
└── assets/                       # CSS and JavaScript
```

## Documentation

For complete technical documentation, see:
`/DOCUMENTATION/PEANUT-LICENSE-SERVER-DOCUMENTATION.md`

## Changelog

### 1.3.1 (2025-12-28)
- Fixed translation loading warning for WordPress 6.7+

### 1.3.0 (2025-12-27)
- Added React SPA admin interface with 11 pages
- Added analytics dashboard

### 1.2.0 (2025-12-20)
- Added admin authentication to API endpoints
- Improved security features

## License

GPL-2.0-or-later

## Author

[Peanut Graphic](https://peanutgraphic.com)
