# AeroGate AODB

A scheduler-driven airport gate allocation system built with Laravel 13 and PostgreSQL 17.

AeroGate periodically retrieves arrivals for a configured airport, records repeated observations idempotently, allocates flights to eligible gates for a configurable occupancy window, and independently audits operational state.

## Challenge and interpretation

The implementation translates the challenge into explicit behavior rather than embedding business rules in cron definitions or controllers.

| Challenge requirement | Implementation |
|---|---|
| Generate 20 gates | Deterministic, repeatable gate seeder |
| Fetch arrivals periodically | Scheduled ingestion command and OpenSky adapter |
| Configure the airport | Environment-backed ICAO configuration |
| Occupy a gate for 90 minutes from time `T` | Configurable policy using the first local observation as `T` |
| Assign at most one flight to a gate at a time | Overlap query plus a PostgreSQL exclusion constraint |
| Prevent use of B8 during repairs | Time-bound gate-unavailability record |
| Periodically validate allocations and report statistics | Independent read-only audit command |
| Test the most important behavior | Unit, feature, database-invariant, and multi-process contention tests |

The central invariants are:

- active allocations for the same gate never overlap;
- a flight has at most one active allocation;
- occupancy and unavailability intervals use UTC half-open ranges `[start, end)`;
- a new allocation cannot overlap a known gate restriction;
- repeated provider delivery converges on one logical flight and allocation;
- an allocation is confirmed only after its authoritative transaction commits.

The complete functional and non-functional requirements, assumptions, and deferred scope are in [docs/requirements.md](docs/requirements.md).

## Architecture at a glance

AeroGate is a pragmatic modular monolith. Delivery mechanisms stay thin, provider-specific details are translated at the boundary, application services coordinate use cases, and PostgreSQL owns cross-process safety invariants.

```text
Laravel Scheduler
       │
       ▼
Artisan Commands
  ingestion / audit
       │
       ▼
Application Services
  ingestion / allocation / auditing
       │                    │
       ▼                    ▼
FlightProvider          PostgreSQL
contract + adapter      state + invariant guards
       │
       ▼
OpenSky HTTP API
```

The scheduler is a delivery mechanism; it does not own allocation rules. Scheduled invocation adds overlap and single-server guards, while direct or concurrent execution remains safe through idempotency, row locking, and database constraints.

### Ingestion and allocation flow

```text
Scheduled or manual command
    → construct a configured historical arrival query
    → fetch and normalize arrivals through FlightProvider
    → atomically insert or lock and refresh each logical flight
    → calculate [first observation, first observation + duration)
    → lock the flight and select an eligible gate
    → create an allocation or mark the flight UNASSIGNED
    → emit a correlated operational summary
```

Expected capacity exhaustion is a typed outcome, not an exception. Provider and infrastructure failures remain distinguishable from a successful empty result.

### Allocation safety

Allocation uses layered protection:

1. A short transaction locks the flight so duplicate delivery converges.
2. The eligibility query excludes inactive, restricted, and occupied gates.
3. `FOR UPDATE SKIP LOCKED` prevents workers from unnecessarily waiting on the same candidate gate.
4. A partial unique index allows only one active allocation per flight.
5. A GiST exclusion constraint authoritatively rejects overlapping active allocations for one gate.
6. Only PostgreSQL exclusion conflicts are retried, with a fixed bound.

`SKIP LOCKED` improves contention behavior; it is not the correctness boundary.

### Independent audit flow

```text
Independent scheduled command
    → read authoritative state
    → calculate flight, allocation, and gate-capacity statistics
    → detect stale unassigned flights and maintenance conflicts
    → emit stable console metrics and structured logs
```

The audit detects and reports. It does not silently modify allocations or perform automatic cascade reallocation.

## Implemented scope

### Challenge baseline

- Deterministic seeding of 20 active gates and B8's time-bound repair period.
- Environment-configured airport, provider query window, and occupancy duration.
- Scheduled ingestion and audit commands.
- Configurable 90-minute occupancy from first observation.
- Maintenance, inactive-gate, and existing-allocation eligibility checks.
- Explicit `UNASSIGNED` state when no gate is available, with later retry support.
- Audit metrics for gate capacity, lifecycle states, stale flights, and maintenance conflicts.

### Correctness and resilience extensions

- A normalized provider contract with a deterministic test fake.
- OpenSky integration with optional OAuth2, validation, timeouts, bounded retries, and classified failures.
- Provider-scoped flight identity and idempotent observation persistence.
- UTC half-open range semantics across policy, queries, generated columns, constraints, and tests.
- Transactional allocation using flight and candidate-gate locks.
- PostgreSQL GiST exclusion and partial unique constraints.
- Structured command logs, safe console failures, run IDs, and meaningful exit codes.
- Real multi-process PostgreSQL contention tests.

### Deliberately deferred

The following are evolution paths, not claimed as implemented:

- aircraft-size, terminal, airline, bridge, and Schengen-zone compatibility;
- automatic delay propagation and cascade reallocation;
- operator locks, overrides, approvals, and append-only decision history;
- Redis or SQS queues, Laravel Horizon, and dead-letter handling;
- AWS scheduling and managed infrastructure;
- multi-airport gate ownership and workload partitioning;
- global gate-plan optimization.

## Key technical decisions

- **Laravel modular monolith:** Laravel supplies scheduling, HTTP, configuration, dependency injection, migrations, transactions, logging, and testing. One deployable avoids premature distributed failure modes while explicit boundaries preserve extraction options.
- **PostgreSQL authority:** range types, GiST indexing, transactions, row locks, and exclusion constraints enforce the main safety invariant atomically. The trade-off is intentional PostgreSQL coupling.
- **UTC half-open ranges:** `[start, end)` allows adjacent allocations and avoids local-time ambiguity. A turnaround buffer would extend the end instant instead of changing interval semantics.
- **Separate gate restrictions:** global inactivity and scheduled maintenance are different facts. Time-bound records preserve that distinction at the cost of an additional eligibility check.
- **Synchronous local execution:** the local workflow stays deterministic and needs no Redis or cloud account. Idempotent services and database invariants preserve an at-least-once queue evolution path.
- **Interfaces at volatile boundaries:** `FlightProvider` isolates external integrations, while focused Eloquent and PostgreSQL code remains direct. Repository interfaces are not added without a concrete need.
- **Custom Docker rather than Sail:** the two-service runtime makes PHP extensions, PostgreSQL, and service networking explicit without adopting additional wrapper conventions.

Laravel was chosen for delivery confidence and its batteries-included application infrastructure. Go becomes a reasonable extraction option only when measurements identify a bounded CPU-, memory-, or concurrency-constrained component, or when independent deployment and ownership justify the added distributed-system cost.

See [docs/architecture.md](docs/architecture.md) and [docs/decisions.md](docs/decisions.md) for the complete design and trade-offs.

## Prerequisites

- Docker with Docker Compose
- Make

No host PHP, Composer, PostgreSQL, Redis, or AWS account is required.

## Quick start

From a fresh checkout:

```bash
make setup
make db-fresh
make quality
make demo
```

`make setup` creates `.env` from `.env.example` when missing, builds and starts the containers, generates the Laravel application key, and applies migrations.

`make db-fresh` is destructive: it rebuilds the development database and seeds the deterministic 20-gate scenario. It does not target the dedicated test database used by PHPUnit.

Inspect all available commands with:

```bash
make help
```

## Demonstration and execution

### Deterministic offline demonstration

```bash
make demo
```

The demo executes fake-provider ingestion and allocation scenarios, the PostgreSQL-backed audit scenario, and prints the registered scheduler events. It demonstrates the application without OpenSky credentials or network availability.

For a clean seeded operational snapshot:

```bash
make db-fresh
make audit
```

The audit initially reports 20 total/free gates and zero flights or anomalies. B8's seeded January 2025 restriction is historical at the current date, so it is not counted as unavailable now.

### Fetch and allocate arrivals

```bash
make ingest
```

The default `FlightProvider` adapter calls OpenSky. A successful run prints created, updated, allocated, unassigned, and failed counts plus a run ID.

OpenSky's arrivals endpoint is populated by a nightly batch and is used as a challenge integration rather than presented as a real-time operational AODB feed. The default query therefore ends 24 hours behind the current time.

### Audit allocations

```bash
make audit
```

The read-only audit reports stable `name=value` metrics covering:

- flight and allocation lifecycle states;
- total, active, inactive, occupied, unavailable, and free gates;
- stale unassigned flights;
- active allocations that overlap gate maintenance;
- total anomaly occurrences.

### Inspect or run the scheduler

```bash
make schedule-list
docker compose exec app php artisan schedule:run
```

Laravel defines ingestion and audit as independent five-minute events with overlap and single-server guards. Defining a schedule does not start a daemon. A deployed host must invoke `schedule:run` every minute or supervise `php artisan schedule:work`.

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
| `OPENSKY_CLIENT_SECRET` | empty | Optional matching client secret; both credentials must be set together. |
| `OPENSKY_CONNECT_TIMEOUT_SECONDS` | `5` | Connection timeout. |
| `OPENSKY_REQUEST_TIMEOUT_SECONDS` | `15` | Overall request timeout. |
| `OPENSKY_HTTP_ATTEMPTS` | `3` | Total attempts for transient failures. |
| `OPENSKY_RETRY_DELAY_MILLISECONDS` | `250` | Initial exponential-backoff delay. |
| `APP_PORT` | `8000` | Host port exposed by the application container. |
| `FORWARD_DB_PORT` | `5432` | Host port exposed by PostgreSQL. |

After changing `.env` while configuration is cached, run:

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

## Logging and failure behavior

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

Detailed exceptions stay in application logs. Console failures expose a generic message and run ID so an operator can correlate the failure without leaking provider payloads, credentials, SQL, or stack traces. Local logs are written under `storage/logs/`.

Finding no available gate is a successful operational result. Provider unavailability, malformed data, and internal persistence failures remain explicit failures and produce a non-zero command exit code.

## Scaling and production evolution

The local topology intentionally contains only the application and its authoritative PostgreSQL database:

```text
Scheduler → synchronous Laravel workflow → PostgreSQL
```

The first production scaling step is durable asynchronous delivery:

```text
Managed scheduler
    → Redis/SQS queue
    → horizontally scaled stateless Laravel workers
    → authoritative PostgreSQL writer
```

- At-least-once delivery reuses the existing idempotent handlers.
- Queue depth, oldest-job age, worker utilization, retries, and dead letters provide backpressure visibility.
- Connection pooling protects PostgreSQL from unconstrained worker connections.
- Read replicas can serve audit and dashboard workloads but must not make allocation decisions from stale state.
- Work and worker pools can be partitioned by airport while preserving one write authority for each gate schedule.
- A transactional outbox can publish committed allocation events to independent read projections without a dual-write race.

The current schema represents one configured airport: flights carry an ICAO code, but gates do not yet have explicit airport ownership. True multi-airport execution requires an `Airport` boundary, airport-scoped gates and invariants, and airport-partitioned delivery.

During a distributed-system partition, authoritative allocation favors consistency: if PostgreSQL cannot be reached, work remains pending rather than confirming a potentially conflicting gate. Reporting and buffered ingestion may tolerate eventual consistency and reconcile later.

Service extraction is justified by measured throughput, fault isolation, deployment, or ownership requirements—not by record count alone. Provider ingestion, reporting projections, or a future optimization engine are safer extraction candidates than creating multiple independent writers for the same gate schedule.

## Project structure

```text
app/
├── Application/     use-case orchestration: ingestion, allocation, audit
├── Console/         Artisan delivery adapters
├── Contracts/       external-provider contract and normalized boundary data
├── Domain/          value objects, policies, typed results, and reports
├── Infrastructure/  OpenSky, PostgreSQL read/write algorithms, system clock
├── Models/          Eloquent persistence models and focused query scopes
└── Providers/       Laravel dependency composition root
```

Interfaces are introduced where variation is concrete rather than around every model. The Eloquent models are deliberately pragmatic persistence models, not a claim of a fully persistence-independent domain model.

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

The Docker initialization script creates `aerogate_testing` only when PostgreSQL initializes a new volume. For a disposable local environment, run:

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
