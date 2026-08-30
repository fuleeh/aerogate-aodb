# Airport Gate Allocation System — Requirements

## 1. Purpose

Build a scheduler-driven application that periodically retrieves arrivals for a configured airport and assigns each flight to an eligible gate for a configurable occupancy period.

The solution must be easy to run locally, correct under repeated or concurrent processing, and structured so production infrastructure can be introduced without rewriting the core allocation rules.

## 2. Requirement classification

- **Baseline** — explicitly required by the interview challenge.
- **Extension** — implemented to demonstrate a realistic rule without changing the baseline.
- **Evolution** — designed and discussed, but not claimed as implemented.

## 3. Functional requirements

| ID | Requirement | Classification |
|---|---|---|
| FR-01 | Seed 20 gates that can participate in allocation. | Baseline |
| FR-02 | Configure the target airport ICAO code through environment-backed configuration. | Baseline |
| FR-03 | Periodically retrieve airport arrivals through an external flight provider. | Baseline |
| FR-04 | Record repeated flight observations idempotently. | Baseline |
| FR-05 | Allocate a flight from its first observation time for a configurable duration, initially 90 minutes. | Baseline |
| FR-06 | Assign at most one flight to a gate at any instant. | Baseline |
| FR-07 | Exclude gates during dynamic unavailability periods such as maintenance. | Baseline |
| FR-08 | Keep a flight unassigned with an explicit reason when no eligible gate exists. | Baseline |
| FR-09 | Run an independent periodic audit that reports allocation statistics and anomalies. | Baseline |
| FR-10 | Normalize provider payloads before they reach allocation logic. | Extension |
| FR-11 | Restrict assignments using aircraft/gate size categories when reliable aircraft metadata is available. | Evolution |
| FR-12 | Explain why a gate or flight could not be allocated through stable reason codes. | Extension |

## 4. Non-functional requirements

| ID | Requirement |
|---|---|
| NFR-01 | **Correctness:** concurrent execution must not produce overlapping allocations. |
| NFR-02 | **Reliability:** provider failures must not be interpreted as a successful empty response. |
| NFR-03 | **Idempotency:** duplicate polling or job delivery must not duplicate logical flights or allocations. |
| NFR-04 | **Maintainability:** commands remain thin and external provider details remain outside core workflows. |
| NFR-05 | **Testability:** time, provider responses, allocation rules, and PostgreSQL invariants are testable deterministically. |
| NFR-06 | **Observability:** every command run emits a run identifier, outcome, duration, counts, and useful failure context. |
| NFR-07 | **Security:** secrets stay outside source control and untrusted provider data is validated at the boundary. |
| NFR-08 | **Portability:** the application runs locally through Docker without AWS dependencies. |
| NFR-09 | **Scalability:** application workers remain stateless; correctness does not depend on one PHP process. |
| NFR-10 | **Simplicity:** abstractions require a concrete clarity, variation, testing, or correctness benefit. |

## 5. Assumptions

- All stored operational timestamps represent UTC instants.
- Occupancy uses a half-open interval: `[start, end)`. An allocation ending at 11:00 does not conflict with one starting at 11:00.
- The baseline window is `[first_observed_at, first_observed_at + configured_duration)`.
- PostgreSQL is the authoritative source for gates, restrictions, flights, and allocations.
- Callsign alone is not assumed to be a stable unique flight identity.
- Upstream arrivals may be duplicated, delayed, incomplete, or corrected.
- No available gate is an expected operational outcome, not a system exception.
- The baseline uses deterministic first-fit allocation; it does not claim globally optimal gate utilization.
- Authentication, operator UI, and full airport operational integration are outside the challenge scope.

## 6. Core invariants

| ID | Invariant |
|---|---|
| INV-01 | Allocations for the same gate must never overlap. |
| INV-02 | A flight must not have more than one active allocation. |
| INV-03 | Every occupancy and unavailability interval must have `start < end`. |
| INV-04 | An allocation must not overlap an active gate-unavailability interval. |
| INV-05 | Only active gates satisfying all known compatibility rules are eligible. |
| INV-06 | Reprocessing the same external flight must preserve one logical flight record. |
| INV-07 | An allocation is confirmed only after its authoritative database transaction commits. |

## 7. Deferred production evolution

The architecture should make these changes explainable without pretending they are part of the baseline:

- Redis or SQS queues and Laravel Horizon workers.
- Managed scheduling through AWS EventBridge.
- Aircraft-size, terminal, airline, bridge, and Schengen-zone constraints.
- Manual locks, overrides, optimistic concurrency, and append-only audit history.
- Maintenance overrun detection and bounded reallocation proposals.
- Frozen operational windows and operator approval for high-impact changes.
- Global optimization using constraint programming when greedy allocation is insufficient.
- Multi-airport partitioning and extraction of a high-throughput allocator into Go when measurements justify it.
- Metrics, alerting, dead-letter handling, and operational dashboards.

## 8. Acceptance criteria

The baseline is complete when:

1. A new developer can start the application and PostgreSQL using documented Docker commands.
2. The database can be rebuilt and seeded with 20 gates and deterministic maintenance data.
3. A fake provider can drive the complete ingestion and allocation flow without network access.
4. OpenSky can be used through the same internal provider contract.
5. Eligible flights are allocated and capacity exhaustion produces an explicit unassigned outcome.
6. Tests prove overlap boundaries, maintenance exclusion, idempotency, and database enforcement.
7. The ingestion and audit commands are registered with non-overlapping scheduler execution.
8. The audit command reports useful statistics and detects deliberately introduced anomalies where possible.
