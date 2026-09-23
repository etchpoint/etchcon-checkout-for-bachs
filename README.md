# Payment Integrations for Bachs

Open-source WordPress payment integrations for Bachs, maintained by Etchpoint.

## Current version

`1.0.0` is being prepared as the first public release candidate.

## Current integration

WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms Pro, and GiveWP one-time hosted checkout integrations are implemented on the shared payment core. The core provides payment intents, exact money handling, Bachs API access, signed webhook verification, event deduplication, verified payment evidence, reconciliation, and provider-confirmed refunds.

## Refunds

Bachs refunds are requested from **Bachs Payments → Refunds**. Full and partial refunds are supported, but each Bachs charge can have only one refund operation. A partial refund therefore consumes the refund operation for that charge.

The plugin persists the logical refund before contacting Bachs, uses a stable idempotency key, and waits for signed refund webhook evidence before finalizing the corresponding WooCommerce, Paid Memberships Pro, Gravity Forms, Fluent Forms, or GiveWP record. Configure the Bachs webhook endpoint to receive the refund lifecycle events used by your account, including `refund.created`, `refund.paid`, and `refund.failed`.

## Architecture

A public technical overview is available in [`docs/architecture.md`](docs/architecture.md). Internal implementation planning and research notes are maintained separately from the public repository.

## Requirements

- PHP 8.1+
- WordPress 6.8+
- WooCommerce 8.3+ for the WooCommerce adapter
- GiveWP 4.0+ for the GiveWP adapter
- Gravity Forms 2.9+ for the Gravity Forms adapter
- Fluent Forms Pro with its payment extension APIs for the Fluent Forms adapter
- Composer 2.x for development

## Development setup

```bash
composer install
composer qa
```

The repository should commit a genuine `composer.lock` after dependencies are resolved in a Composer-enabled development environment.

## Quality gates

```bash
composer lint
composer analyse
composer test
composer audit
```

GitHub CI also builds a production-only plugin directory and runs the official WordPress Plugin Check action against that release candidate.

## Release packaging

The WordPress.org release ZIP must include the production Composer autoloader and runtime dependencies:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

The CI release-candidate job packages only production files and uploads an installable ZIP after Plugin Check passes. The exact packaged ZIP must still be tested on a clean WordPress installation before distribution.

Plugin-owned payment, webhook event, and refund audit records are intentionally retained on uninstall; this behavior is disclosed in `readme.txt`.
