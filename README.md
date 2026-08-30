# AeroGate AODB

A scheduler-driven airport gate allocation system built with Laravel and PostgreSQL.

The project is under active implementation. Its requirements, architecture, and trade-offs are documented in [`docs/`](docs/).

## Current development requirements

- PHP 8.3 or newer
- Composer 2

## Verify the scaffold

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

The next milestone adds the reproducible Docker and PostgreSQL development environment.
