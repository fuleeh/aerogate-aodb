# AeroGate AODB

A scheduler-driven airport gate allocation system built with Laravel and PostgreSQL.

The project is under active implementation. Its requirements, architecture, and trade-offs are documented in [`docs/`](docs/).

## Development requirements

- Docker with Docker Compose
- Make

## Setup

```bash
make setup
make quality
```

Rebuild the local database with the deterministic 20-gate demonstration scenario:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

Run `make help` to list the available development commands.

First-party PHP code follows PSR-12 with strict types. Laravel Pint checks formatting, Larastan/PHPStan runs at level 8 without a generated baseline, and PHPUnit verifies behavior.

Tests use a dedicated PostgreSQL database so PostgreSQL-specific ranges, constraints, and locking behavior are exercised rather than substituted with SQLite.
