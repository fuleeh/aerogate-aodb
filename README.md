# AeroGate AODB

A scheduler-driven airport gate allocation system built with Laravel 13 and PostgreSQL 17.

The application periodically retrieves arrivals for a configured airport, records observations idempotently, allocates flights to available gates for a configurable occupancy window, and independently audits operational state.

## What is implemented

- Deterministic seeding of 20 gates and B8's time-bound maintenance period.
- OpenSky arrivals adapter with optional OAuth2, validation, bounded retries, and failure classification.
- Idempotent flight ingestion using provider-scoped identity.
- Configurable 90-minute occupancy from first observation using UTC `[start, end)` ranges.
- Maintenance, inactive-gate, and existing-allocation eligibility checks.
- Transactional allocation with `FOR UPDATE SKIP LOCKED`.
- PostgreSQL GiST exclusion and partial unique constraints as final concurrency guards.
- Explicit `UNASSIGNED` capacity outcome with later retry support.
- Independent audit reporting gate capacity, state counts, stale flights, and maintenance conflicts.
- Structured command logs, safe console failures, run IDs, and meaningful exit codes.
- Real multi-process PostgreSQL contention tests.

Aircraft size, Schengen-zone compatibility, cascade reallocation, operator overrides, queues, and AWS infrastructure are documented evolution paths, not claimed as implemented.

## Requirements

- Docker with Docker Compose
- Make

No host PHP, Composer, PostgreSQL, Redis, or AWS account is required.

## Quick start

From a fresh checkout:

```bash
make setup
make db-fresh
make quality
```

`make setup` creates `.env` from `.env.example` when missing, builds and starts the containers, generates the Laravel application key, and applies migrations.

`make db-fresh` is destructive: it rebuilds the development database and seeds the deterministic 20-gate scenario. It never targets the dedicated test database used by PHPUnit.

Inspect the available commands:

```bash
make help
```

## Deterministic offline demonstration

Run the core workflows without OpenSky credentials or network access:

```bash
make demo
```

The demo executes fake-provider ingestion and allocation scenarios, the PostgreSQL-backed audit scenario, and prints both registered scheduler events. It proves the application flow while avoiding a live API dependency during review.

For a clean seeded operational snapshot:

```bash
make db-fresh
make audit
```

The audit should initially report 20 total/free gates and zero flights or anomalies. B8's seeded January 2025 restriction remains historical at the current date, so it is not counted as unavailable now.

## Operational commands

### Fetch and allocate arrivals

```bash
make ingest
```

This command uses the configured `FlightProvider`; the default adapter calls OpenSky. A successful run prints created, updated, allocated, unassigned, and failed counts plus a run ID.

OpenSky's arrivals endpoint is populated by a nightly batch and is therefore used as a challenge/demo integration, not presented as a real-time AODB feed. The default query ends 24 hours behind current time for that reason.

### Audit allocations

```bash
make audit
```

The audit is read-only and reports stable `name=value` metrics, including:

- flight and allocation lifecycle states;
- total, active, inactive, occupied, unavailable, and free gates;
- stale unassigned flights;
- active allocations overlapping maintenance;
- total anomaly occurrences.

### Inspect or run the scheduler

```bash
make schedule-list
docker compose exec app php artisan schedule:run
```

Laravel defines both commands on independent five-minute events with overlap and single-server guards. Defining a schedule does not start a daemon. A deployed host must invoke `schedule:run` every minute or supervise `php artisan schedule:work`.

The local cache driver is the shared PostgreSQL database. A multi-host production deployment could use Redis, but scheduler locks are efficiency controls rather than the allocation correctness boundary.

## Configuration

All runtime configuration is environment-backed. Copy `.env.example`; never commit `.env` or real credentials.

| Variable | Default | Purpose |
|---|---:|---|
| `AIRPORT_ICAO` | `EDDF` | Airport queried by scheduled ingestion. |
| `GATE_OCCUPANCY_DURATION_MINUTES` | `90` | Gate occupancy duration from first observation. |
| `FLIGHT_QUERY_WINDOW_MINUTES` | `120` | Duration covered by one provider request; maximum 2,880 for OpenSky. |
| `FLIGHT_QUERY_DELAY_MINUTES` | `1440` | How far behind current time the query ends. |
| `AUDIT_STALE_UNASSIGNED_AFTER_MINUTES` | `15` | Age at which an unassigned flight is reported stale. |
| `OPENSKY_CLIENT_ID` | empty | Optional OpenSky OAuth2 client ID. |
| `OPENSKY_CLIENT_SECRET` | empty | Optional matching client secret. Both credentials must be set together. |
| `OPENSKY_CONNECT_TIMEOUT_SECONDS` | `5` | Connection timeout. |
| `OPENSKY_REQUEST_TIMEOUT_SECONDS` | `15` | Overall request timeout. |
| `OPENSKY_HTTP_ATTEMPTS` | `3` | Total attempts for transient failures. |
| `OPENSKY_RETRY_DELAY_MILLISECONDS` | `250` | Initial exponential-backoff delay. |
| `APP_PORT` | `8000` | Host port exposed by the application container. |
| `FORWARD_DB_PORT` | `5432` | Host port exposed by PostgreSQL. |

Changing `.env` after configuration has been cached requires:

```bash
docker compose exec app php artisan config:clear
```

## Quality and tests

```bash
make quality
```

The aggregate quality command runs:

1. Laravel Pint in check mode using PSR-12;
2. Larastan/PHPStan at level 8 without a generated baseline;
3. the full PHPUnit suite against PostgreSQL.

Tests use the separate `aerogate_testing` database. SQLite is intentionally not substituted because range types, GiST constraints, row locks, SQLSTATE handling, and multi-process behavior are part of the design.

Focused commands are also available:

```bash
make test
make analyse
make lint
make format
```

The concurrency suite requires `pcntl`, which is installed in the custom Docker image. It verifies two synchronized PHP processes, not production load, crash recovery, database failover, or network partitions.

## Project structure

```text
app/
├── Application/     workflow orchestration: ingestion, allocation, audit
├── Console/         Artisan delivery adapters
├── Contracts/       volatile external-provider boundary
├── Domain/          immutable results, policies, and value objects
├── Infrastructure/  OpenSky, PostgreSQL read/write algorithms, system clock
└── Models/          Eloquent persistence models and focused query scopes
```

The project is a modular monolith. Commands remain thin, provider payloads are normalized at the edge, and PostgreSQL owns cross-process safety invariants. Interfaces are introduced where variation is real rather than around every model.

Further design material:

- [Requirements](docs/requirements.md)
- [Architecture](docs/architecture.md)
- [Decision log](docs/decisions.md)

## Logging and failures

Command logs use stable lifecycle event names:

```text
flight_ingestion.started
flight_ingestion.completed
flight_ingestion.completed_with_failures
flight_ingestion.failed
allocation_audit.started
allocation_audit.completed
allocation_audit.failed
```

Detailed exceptions stay in application logs. Console failures expose a generic message and run ID so an operator can correlate safely without leaking provider payloads, credentials, SQL, or stack traces.

Local logs are written under `storage/logs/`.

## Troubleshooting

### Docker services are not running

```bash
make up
docker compose ps
```

If dependencies changed, rebuild with `make build` and start again.

### A host port is already in use

Change `APP_PORT` or `FORWARD_DB_PORT` in `.env`, then recreate the affected containers:

```bash
docker compose up -d --force-recreate
```

### The test database is missing

The Docker initialization script creates `aerogate_testing` only when PostgreSQL initializes a new volume. For a disposable local environment, remove the Compose volumes and rerun setup:

```bash
docker compose down --volumes
make setup
```

This deletes both local development and test database data.

### Permission problems on bind-mounted files

Set `APP_UID` and `APP_GID` in `.env` to the host user's numeric IDs, then rebuild:

```bash
make build
make up
```

### Ingestion returns a provider failure

Use the displayed run ID to inspect `storage/logs/laravel.log`. Check network access, OpenSky availability, credentials, rate limiting, and the configured historical query window. The offline demo remains available with `make demo`.
