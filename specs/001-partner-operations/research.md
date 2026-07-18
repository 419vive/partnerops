# Phase 0 Research: PartnerOps

All technical clarifications from [plan.md](./plan.md) are resolved below.

## Runtime and support window

**Decision**: Use PHP 8.4 and Symfony 7.4 LTS, constrained through Composer with
`extra.symfony.require: 7.4.*` and a committed lockfile.

**Rationale**: Symfony 7.4 is the current long-term-support line and requires PHP
8.2 or newer. PHP 8.4 is stable, supported by the available local ARM runtime,
official container images, GitHub Actions, and all selected packages. Pinning the
minor framework line gets security patches without accidental Symfony 8 upgrades.

**Alternatives considered**: PHP 8.5 provides a longer runway but narrows hosting
compatibility today; PHP 8.3 is already security-only. Symfony 8 is not LTS.

## Application shape and dependencies

**Decision**: Build one conventional Symfony application. Runtime packages are
FrameworkBundle, Runtime, TwigBundle, AssetMapper, SecurityBundle, Form, Validator,
RateLimiter, UID, MonologBundle, Doctrine ORM/DBAL/Migrations, and PostgreSQL. Dev
packages are DoctrineFixturesBundle, Symfony Test Pack, and MakerBundle only while
scaffolding. Remove MakerBundle if it is unused at completion.

**Rationale**: These maintained packages directly implement the required routes,
forms, security, templates, persistence, logging, limits, identifiers, fixtures,
and tests. Thin web/API controllers can share small transaction-owning services,
Doctrine repositories, entities, validators, and voters.

**Alternatives considered**: Webapp Pack installs mailer, messenger, notifier,
translation, and workflow features not required by the spec. API Platform, CQRS,
repository interfaces, a separate frontend, Redis, and a queue add operational or
conceptual boundaries without a second implementation or current workload.

## Twig, CSS, and accessibility validation

**Decision**: Render semantic HTML with Twig auto-escaping, Symfony Forms, modern
CSS, and AssetMapper. Core paths use no JavaScript. Run PHPUnit DOM assertions for
labels/landmarks/errors and one `@axe-core/cli` smoke scan against the seeded live
pages at mobile and desktop widths.

**Rationale**: AssetMapper compiles versioned production assets without a frontend
bundler. Forms provide validation and CSRF integration. A single axe dev dependency
is the smallest repeatable check for the spec's automated accessibility criterion;
it does not enter the production image.

**Alternatives considered**: Vite/Encore and a UI framework add a dependency graph
for one stylesheet. Panther/Playwright duplicate browser automation when no primary
workflow depends on JavaScript. Manual review alone is not repeatable.

## Browser authentication and client isolation

**Decision**: Use Symfony form login, automatic password hashing, login CSRF,
POST-only CSRF-protected logout, throttling, secure HttpOnly SameSite=Lax cookies,
and a user checker for inactive users/archived clients. Scope every client-owned
repository query from the authenticated user, protect loaded objects with voters,
and recheck ownership/active state in mutation services. Cross-client and missing
records return the same generic not-found result.

**Rationale**: Query scoping prevents accidental list leakage, voters protect HTTP
object operations, and service guards cover non-controller callers. The client's
identity always comes from the user or credential, never submitted `client_id`.

**Alternatives considered**: Voters alone do not scope collections. Doctrine global
filters are implicit and easy to disable. PostgreSQL row-level security or separate
schemas are valuable at larger scale but add request-specific connection state to v1.

## Client API credentials

**Decision**: Issue opaque tokens shaped as `ptk_<selector>.<secret>`, with 32 random
secret bytes encoded base64url. Persist the unique selector, short display prefix,
client, lifecycle timestamps, and `HMAC-SHA256(secret, API_TOKEN_PEPPER)` only. Show
plaintext once. Authenticate a stateless `/api/v1` firewall from the Authorization
header and derive the client from the credential. Update `last_used_at` at most hourly.

**Rationale**: A selector provides indexed lookup; a keyed digest prevents an
extracted database from authenticating without the application pepper. High-entropy
machine secrets do not need the cost of human-password hashing.

**Alternatives considered**: Raw stored tokens expose every integration. JWT makes
revocation and archived-client checks harder. Query-string tokens leak through logs
and history. Argon2 per API request is unnecessary for 256-bit random input.

## API errors, rate limits, and idempotency

**Decision**: Return RFC 9457 `application/problem+json` from one response factory
and API exception subscriber. Limit invalid intake by trusted IP and valid traffic
by credential identity; return `429` with `Retry-After`. Require `Idempotency-Key`
on request creation. In one PostgreSQL transaction reserve unique
`(api_credential_id, idempotency_key)` with `INSERT ... ON CONFLICT`, compare a
canonical validated-payload SHA-256 fingerprint, create once, and store the exact
response reference for 24-hour replay. A reused key with a different fingerprint
returns `409`.

**Rationale**: The database unique index is the only concurrency lock required;
the losing transaction waits for the winner and replays its committed result.
Framework limits are sufficient for one host, while documentation states that an
edge proxy remains responsible for volumetric denial-of-service protection.

**Alternatives considered**: Check-then-insert races. In-memory locks fail across
workers. Redis and serializable transactions add infrastructure or contention.
Controller-specific error arrays drift from the published contract.

## Workflow concurrency and auditability

**Decision**: Add an integer Doctrine version field to each service request. Forms
submit the version viewed and mutations perform an optimistic lock before changes;
conflicts return `409` or a refresh message without automatic retry. Application
services append allow-listed audit metadata in the same transaction as state
changes. Audit records store actor/action/subject/client/time/trace identifiers,
never descriptions, comment bodies, headers, tokens, passwords, or request bodies.
PostgreSQL rejects audit updates and deletes.

**Rationale**: Flush-only version checks miss browser think-time when a controller
reloads the newest row. Explicit transactional events are understandable and avoid
generic lifecycle listeners accidentally serializing private fields.

**Alternatives considered**: Last-write-wins loses edits. Pessimistic locks cannot
span a human viewing a form. Generic Doctrine diff listeners are implicit and
leakage-prone. Full event sourcing is far beyond the product requirement.

## PostgreSQL integrity and identifiers

**Decision**: Use Symfony ULIDs as stable public identifiers and bigint internal
keys for joins. Store instants as immutable UTC timestamps and allowance boundaries
as inclusive calendar dates. Normalize slugs/emails in the application and enforce
case-insensitive unique indexes on `lower(value)`. Enable `btree_gist` and enforce
non-overlapping allowance periods with an exclusion constraint on
`client_id` and `daterange(starts_on, ends_on, '[]')`. Enforce positive minutes,
valid date ranges, and enum values with check constraints. Comments, time entries,
and audit events have no product update/delete routes.

**Rationale**: Database constraints protect every caller, including future imports.
ULIDs avoid exposing row counts while remaining sortable. Whole integer minutes
make allowance arithmetic exact and avoid currency/time rounding behavior.

**Alternatives considered**: UUIDv4 is safe but not ordered. Application-only
overlap checks race. One row per monthly balance can drift from time-entry history.
Soft deletion of append-only events weakens the audit trail.

## Query indexes and performance check

**Decision**: Add composite indexes for request lists by `(client_id, status,
created_at)`, `(assignee_id, status)`, and open due work; request comments by
`(service_request_id, created_at)`; time entries by allowance/request; audit events
by `(client_id, occurred_at)`; and idempotency expiry cleanup. Derive current
allowance totals with indexed `SUM(minutes)` queries. Validate with 10,000 request
fixtures, `EXPLAIN (ANALYZE, BUFFERS)`, and a small repeatable HTTP p95 script.

**Rationale**: At the target scale PostgreSQL aggregates and pagination are simpler
and less failure-prone than cache invalidation. Indexes follow actual filter/sort
paths and can be removed or changed from measured plans.

**Alternatives considered**: Materialized views, Redis counters, and background
aggregation are unnecessary below measured query pressure. Offset pagination is
acceptable at 10,000 rows; keyset pagination can replace it if deep-page metrics
show a real problem.

## Testing and delivery

**Decision**: Use PHPUnit unit tests for transition/allowance/token logic,
KernelTestCase for Doctrine integration, and WebTestCase for browser/API contracts
and the complete cross-client denial matrix. Run PostgreSQL-backed tests in CI.
Ship an app plus PostgreSQL Docker Compose stack, an Apache PHP production image
with OPcache and non-root writable runtime directories, deterministic dev fixtures,
separate liveness/readiness endpoints, and one CI gate for Composer validation/audit,
migrations/schema, Symfony/Twig/YAML lint, tests, asset compile, axe smoke, and image build.

**Rationale**: WebTestCase covers routing, security, forms, CSRF, Twig, and JSON
without slow browser fixtures. Real PostgreSQL CI is mandatory for custom constraints,
locking, migrations, and query behavior. Liveness avoids restart loops; readiness
uses a bounded `SELECT 1` and exposes no versions or connection details.

**Alternatives considered**: SQLite-only tests hide PostgreSQL behavior. Database
checks in liveness cause cascading restart loops. Automatically running fixtures in
production can destroy or contaminate customer data.

## Sources verified

- Symfony 7.4 release and setup documentation: <https://symfony.com/releases/7.4>
- Symfony AssetMapper: <https://symfony.com/doc/7.4/frontend/asset_mapper.html>
- Symfony Security, access tokens, CSRF, and Rate Limiter documentation
- Doctrine ORM optimistic locking documentation
- PostgreSQL 16 range/exclusion constraints and `INSERT ... ON CONFLICT`
- RFC 9457 Problem Details for HTTP APIs
