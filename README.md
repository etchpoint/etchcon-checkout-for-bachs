# Payment Integrations for Bachs

Open-source WordPress payment integrations for Bachs, maintained by Etchpoint.

## Current version

`1.0.0` is under active development.

## Current integration

WooCommerce one-time hosted checkout is the first supported adapter being implemented. The shared core provides payment intents, exact money handling, Bachs API access, signed webhook verification, event deduplication, and verified payment evidence.

## Architecture

A public technical overview is available in [`docs/architecture.md`](docs/architecture.md). Internal implementation planning and research notes are maintained separately from the public repository.

## Requirements

- PHP 8.1+
- WordPress 6.8+
- WooCommerce 8.3+ for the WooCommerce adapter
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

## Release packaging

The WordPress.org release ZIP must include the production Composer autoloader and runtime dependencies:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

The packaged ZIP itself must be tested on a clean WordPress installation before distribution.
