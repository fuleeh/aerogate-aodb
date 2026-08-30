# Airport Gate Allocation System — Decision Log

This is a living, concise decision record. Each decision states its cost and the condition that would justify reconsidering it.

## ADR-001 — Use Laravel for the baseline implementation

**Status:** Accepted

**Context:** The challenge requires scheduling, HTTP integration, persistence, transactions, configuration, testing, and operational commands. The implementation must be explained deeply during an interview.

**Decision:** Build a Laravel modular monolith.

**Why:** Laravel provides the required primitives with low delivery risk, and it matches existing experience and the company's stack.

**Alternatives:** Go service; Node.js service; multiple services from the start.

**Trade-offs:** PHP worker processes have a different throughput and memory profile from a long-running Go service. Laravel conventions can also hide behavior if used without discipline.

**Reconsider when:** Measurements show the allocator is CPU-bound, it needs independent deployment/scaling, or ownership boundaries justify extraction. Extraction would be easier, not free.

## ADR-002 — Start with a modular monolith

**Status:** Accepted

**Context:** The challenge represents one cohesive workflow and one authoritative data model.

**Decision:** Keep ingestion, allocation, and auditing in one deployable application with explicit internal boundaries.

**Why:** It minimizes distributed failure modes and operational overhead while preserving clear responsibilities.

**Alternatives:** Microservices, serverless functions, or a separate allocator immediately.

**Trade-offs:** Components cannot initially deploy or scale independently.

**Reconsider when:** Team ownership, release cadence, fault isolation, or measured scaling needs become independently significant.

## ADR-003 — Use PostgreSQL as the authoritative datastore

**Status:** Accepted

**Context:** Time-range overlap is the primary correctness risk and must remain safe across concurrent processes.

**Decision:** Use PostgreSQL range types, GiST indexing, transactions, and exclusion constraints.

**Why:** PostgreSQL can express and atomically enforce the no-overlap invariant rather than relying only on race-prone application checks.

**Alternatives:** MySQL with explicit locking; SQLite locally; Redis locks; a single serialized allocation worker.

**Trade-offs:** The schema and important tests are PostgreSQL-specific. Local development requires PostgreSQL rather than a lightweight SQLite substitute.

**Reconsider when:** Database portability becomes a genuine requirement and an equally safe serialization mechanism is designed and tested.

## ADR-004 — Use a minimal custom Docker environment

**Status:** Accepted

**Context:** Reviewers need a deterministic setup with the same database capabilities used by production-oriented code.

**Decision:** Provide a small Docker Compose topology containing a custom Laravel runtime and PostgreSQL.

**Why:** The two-service topology is explicit, easy to explain, and contains only what the challenge uses. It standardizes PHP extensions and the database version without cloud dependencies.

**Alternatives:** Host-installed PHP/PostgreSQL; Laravel Sail; a larger production-like stack.

**Trade-offs:** We own a small amount of container configuration and must maintain it as PHP requirements change. Docker adds build time and requires Docker Desktop or an equivalent runtime.

**Reconsider when:** The target review environment prohibits Docker or the project adopts a standardized company development environment.

## ADR-005 — Model occupancy as UTC half-open ranges

**Status:** Accepted

**Context:** Interval boundaries must be unambiguous and aviation timestamps represent global instants.

**Decision:** Normalize instants to UTC and model occupancy as `[start, end)` using PostgreSQL `tstzrange` semantics.

**Why:** Adjacent assignments are allowed naturally, timezone ambiguity is reduced, and one overlap definition can be used consistently.

**Alternatives:** Inclusive ranges; independent timestamp comparisons; local airport time storage.

**Trade-offs:** Developers must understand range bounds and convert for presentation at system edges.

**Reconsider when:** Operational policy requires an explicit turnaround buffer, which should extend the end instant rather than change interval semantics.

## ADR-006 — Keep maintenance separate from gate active state

**Status:** Accepted

**Context:** A permanent disabled flag cannot represent a restriction from January 10 to January 11 or a later extension.

**Decision:** Store time-bound gate-unavailability records separately from a gate's global active state.

**Why:** It models the requirement directly, preserves history, and supports multiple reasons and periods.

**Alternatives:** Toggle `is_active`; hard-code B8; encode maintenance as a special allocation.

**Trade-offs:** Eligibility queries must check two sources of availability.

**Reconsider when:** Unavailability rules become complex enough to require approvals, recurrence, or a dedicated operational restriction model.

## ADR-007 — Add interfaces only at volatile boundaries

**Status:** Accepted

**Context:** The design should follow SOLID and remain testable without becoming pattern-heavy.

**Decision:** Introduce contracts for external flight providers and genuinely variable policies. Use focused application services and immutable DTOs, but do not wrap every Eloquent model in a repository.

**Why:** This isolates external change and improves deterministic testing while keeping framework-native persistence readable.

**Alternatives:** Repository interfaces for every model; generic service hierarchies; no boundaries around integrations.

**Trade-offs:** Some application services remain coupled to Eloquent/PostgreSQL intentionally.

**Reconsider when:** Multiple persistence implementations, complex aggregate persistence, or a service extraction creates a concrete need.

## ADR-008 — Start synchronously and preserve queue-safe semantics

**Status:** Accepted

**Context:** Redis and Horizon are useful production tools but are not required to prove the core challenge locally.

**Decision:** Execute the baseline command synchronously while making ingestion and allocation idempotent and independently invokable.

**Why:** This keeps setup and demonstrations deterministic without preventing later at-least-once queued execution.

**Alternatives:** Redis/Horizon from the first commit; SQS; one job per flight immediately.

**Trade-offs:** A large batch initially runs in one command process and has limited horizontal throughput.

**Reconsider when:** API volume, processing latency, retry isolation, or backlog observability requires asynchronous workers.

## ADR-009 — Favor consistency for authoritative allocation writes

**Status:** Accepted

**Context:** A distributed production system can experience communication partitions. Conflicting offline gate decisions cannot be safely merged using last-write-wins.

**Decision:** Only the authoritative database writer may confirm allocations. If it cannot be reached, allocation remains pending. Delay-tolerant telemetry and ingestion buffers may remain available and reconcile later.

**Why:** Temporary inability to allocate is safer than confirming physical conflicts.

**Alternatives:** Multi-writer allocation with later reconciliation; cache-based authority; offline last-write-wins.

**Trade-offs:** The allocation write path sacrifices availability during loss of authoritative connectivity.

**Reconsider when:** Ownership can be partitioned into non-overlapping gate schedules with a single authority per partition.

## ADR-010 — Enforce quality before domain implementation

**Status:** Accepted

**Context:** Correctness-sensitive allocation code benefits from fast feedback beyond runtime tests, and adding rules after domain implementation creates avoidable cleanup.

**Decision:** Use strict types, PSR-12 through Laravel Pint, Larastan/PHPStan at level 8 without a generated baseline, and PHPUnit. Expose one aggregate quality command.

**Why:** The tools cover complementary risks: formatting consistency, type/data-flow defects, and behavioral correctness. Introducing them before domain code prevents gradual standards drift.

**Alternatives:** PHP-CS-Fixer directly; PHP_CodeSniffer; Psalm; tests alone; maximum PHPStan strictness immediately.

**Trade-offs:** Analysis and formatting add build time, and framework-aware static analysis occasionally requires precise PHPDoc. Level 8 is strict without forcing premature workarounds for every implicit mixed boundary.

**Reconsider when:** The codebase can move to a higher level without suppressions, or company-wide tooling mandates a different formatter or analyser.
