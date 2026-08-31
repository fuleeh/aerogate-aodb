# Airport Gate Allocation System — Architecture

## 1. Architectural intent

Use a pragmatic Laravel modular monolith with PostgreSQL as the authoritative datastore. Keep delivery mechanisms and external integrations at the edges, business workflows explicit, and safety invariants enforced as close to the data as practical.

The architecture optimizes first for correctness, deterministic local execution, and explainability. Production scaling paths are identified but are not local runtime dependencies.

## 2. From requirements to design

The central design chain is:

```text
Requirements
    → operational invariants
    → entities and database constraints
    → application use cases
    → external and delivery interfaces
    → runtime topology
```

The scheduler is a delivery mechanism. It triggers use cases but does not own allocation rules.

## 3. Core entities

### Flight

A normalized arrival observed through an external provider. It owns provider identity, airport, operational timestamps, callsign/aircraft identifiers when available, and processing state.

### Gate

A physical resource that can receive allocations. It owns a stable code, global active state, deterministic selection order, and later may own compatibility attributes.

### Gate allocation

The authoritative decision that a flight occupies a gate during a time range. It records the assignment source and decision state in addition to the flight, gate, and occupancy period.

### Gate unavailability

A time-bound gate restriction with a reason. Maintenance is modeled as data rather than a hard-coded condition or permanent inactive flag.

## 4. Component boundaries

```text
Laravel Scheduler
       │
       ▼
Console Commands
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

### Delivery layer

Artisan commands validate invocation parameters, acquire run-level protection, invoke one application use case, render a concise summary, and return a meaningful exit code.

### Application layer

- The ingestion service coordinates normalization, idempotent persistence, and allocation.
- The allocation service computes the occupancy window, finds an eligible gate, and records the decision transactionally.
- The audit service calculates statistics and detects operational anomalies independently of ingestion.

### Infrastructure layer

- The OpenSky adapter translates provider-specific HTTP payloads and failures into internal data and exceptions.
- Eloquent and focused PostgreSQL queries persist state.
- PostgreSQL constraints provide the final protection for cross-process invariants.

Interfaces are added at volatile boundaries. Eloquent models will not receive speculative repository wrappers.

## 5. Primary contracts

The external provider boundary returns normalized, valid data rather than OpenSky-shaped arrays:

```text
FlightProvider.arrivals(airport, time window) → iterable<ExternalFlightData>
```

The occupancy policy isolates the challenge's current time-window rule from possible future arrival/departure semantics:

```text
OccupancyWindowPolicy.forFirstObservation(timestamp) → OccupancyWindow
```

Expected business outcomes use typed results and reason codes. Infrastructure failures use exceptions. `NO_GATE_AVAILABLE` is a result, not an exception.

## 6. Ingestion and allocation flow

```text
Scheduler tick
    │
    ▼
Acquire ingestion mutex
    │
    ▼
Fetch arrivals through FlightProvider
    │
    ▼
Validate and normalize provider records
    │
    ▼
Upsert each logical flight idempotently
    │
    ▼
Compute [first observed, first observed + duration)
    │
    ▼
Transactionally select and lock an eligible gate
    │
    ├── gate found → create allocation → ALLOCATED
    └── none found → record reason → UNASSIGNED
```

A failed provider request never becomes an empty successful batch. Item-level malformed data and batch-level failures will be distinguished explicitly.

## 7. Audit flow

```text
Independent scheduler tick
    │
    ▼
Audit current authoritative state
    ├── allocated and unassigned flights
    ├── free, occupied, inactive, and unavailable gates
    ├── stale pending flights
    ├── allocations conflicting with changed restrictions
    └── recent processing failures where recorded
    │
    ▼
Structured report, logs, and production alert hooks
```

Database constraints prevent known invalid writes. Auditing detects stale state, changed business facts, integration failures, and rules that are not suitable for a simple constraint.

## 8. Concurrency and correctness

Application-level `check then insert` is unsafe because multiple workers may observe the same gate as free.

The intended defense is layered:

1. Keep the candidate-selection transaction short.
2. Lock candidate gate rows so competing workers do not choose the same resource unnecessarily.
3. Use PostgreSQL range-overlap queries with half-open UTC intervals.
4. Enforce non-overlapping gate allocations with a GiST exclusion constraint.
5. Recognize constraint conflicts and retry only when doing so is safe and bounded.

The exact use of `FOR UPDATE SKIP LOCKED` will be verified against real PostgreSQL behavior. It is a throughput mechanism, not a replacement for the database invariant.

## 9. Local topology

```text
Docker Compose
├── app: custom Laravel CLI/application runtime
└── postgres: authoritative PostgreSQL database
```

Commands are run manually for a deterministic demonstration. Laravel's schedule definition is inspectable with `schedule:list` and executable with `schedule:run`.

The local image remains deliberately small and is not presented as the final production deployment design. Redis, Horizon, and cloud services are excluded until a demonstrated requirement justifies them.

## 10. Production evolution

```text
Managed scheduler
       │
       ▼
Durable queue
       │
       ▼
Stateless Laravel workers ─────► authoritative PostgreSQL writer
       │                                      │
       └──────── metrics / logs / alerts ◄────┘
```

- Delivery is at least once; handlers remain idempotent.
- Work can be partitioned by airport while preserving one write authority for a gate schedule.
- Read replicas may serve reporting but must not make allocation decisions from stale state.
- A Go allocator becomes reasonable only if measured CPU/throughput, deployment independence, or team ownership warrants extraction.

## 11. Partition behavior

CAP is applied per distributed workflow, not as a label on Laravel or PostgreSQL:

- Gate allocation favors consistency during a partition. If authoritative state is unreachable, the system leaves work pending rather than confirming a potentially conflicting assignment.
- Ingestion buffering, logs, and metrics may favor availability and reconcile later.
- The local single-writer topology is a non-partitioned transactional system; it does not claim continued availability through a database partition.

## 12. Human and disruption boundaries

Production automation must not silently fight operators. Manual locks and overrides require actor, reason, timestamp, versioning, and audit history. Maintenance extensions should detect impacted allocations, preserve frozen or manually locked decisions, and produce bounded reallocation proposals or operator escalation rather than an uncontrolled cascade.
