# Payment Integrations for Bachs

WordPress payment integrations for Bachs, maintained by Etchpoint.

## Current version

`1.0.0` is the initial plugin version and is under active development.

## Architecture

A public technical overview is available in [`docs/architecture.md`](docs/architecture.md). Internal implementation planning and research notes are maintained separately from the public repository.

## Requirements

- PHP 8.1+
- WordPress 6.8+
- Composer 2.x for development

## Development setup

```bash
composer install
composer qa
```

The repository must commit a real `composer.lock` after dependencies are resolved in a Composer-enabled environment. Do not hand-create or approximate the lock file.

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

The packaged ZIP itself must be tested before distribution.

## Status

Step 1 provides only the repository, bootstrap, compatibility checks, static-analysis/test configuration and CI. Payment logic starts in later build steps.
