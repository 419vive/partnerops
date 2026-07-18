# Implementation Plan: Partner Service Operations Portal

**Branch**: `001-partner-operations` | **Date**: 2026-07-18 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-partner-operations/spec.md`

## Summary

Build PartnerOps as one production-oriented Symfony application with a
server-rendered Traditional Chinese Twig interface and a small client-scoped JSON
API. PostgreSQL stores clients, users, requests, discussions, service allowances,
time entries, audit history, integration credentials, and idempotency records.
Framework-native authentication, authorization voters, validation, forms, rate
limiting, migrations, and health checks provide the security and maintenance
surface expected from a long-term backend partner without adding a SPA, queue,
cache service, or billing subsystem.

## Technical Context

**Language/Version**: PHP 8.4 with Symfony 7.4 LTS; HTML5, CSS, and minimal vanilla JavaScript for optional enhancement

**Primary Dependencies**: Symfony FrameworkBundle, SecurityBundle, TwigBundle, Form, Validator, RateLimiter, AssetMapper, UID and MonologBundle; Doctrine ORM/DBAL, Migrations and Fixtures

**Storage**: PostgreSQL 16; database-backed sessions are not required for v1

**Testing**: PHPUnit with Symfony WebTestCase/KernelTestCase against PostgreSQL, plus an `@axe-core/cli` seeded-page accessibility smoke scan

**Target Platform**: Linux containers via Docker Compose; GitHub Actions on Linux

**Project Type**: Single server-rendered web application with a versioned JSON API

**Performance Goals**: Dashboard and first filtered request page respond within 750 ms at the server with 10,000 seeded requests; API idempotent replay creates exactly one record

**Constraints**: Strict client data isolation; CSRF on browser mutations; hashed API secrets; UTC instants with Asia/Taipei display; keyboard-accessible core flows; no JavaScript dependency for primary workflows; clean setup within 15 minutes

**Scale/Scope**: One consulting team, up to 100 client accounts, 250 users, 10,000 requests, and 100,000 append-only comments/time/audit rows; five user stories and one external API version

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Pre-design result | Evidence / design obligation |
|---|---|---|
| Secure boundaries first | PASS | Authentication is mandatory; client access is enforced through voters/repository scoping and negative HTTP tests. Token, CSRF, and rate-limit designs remain Phase 0 research items. |
| Data integrity and traceability | PASS | PostgreSQL constraints, transactions, optimistic locking, and immutable events/entries are explicit design targets. Exact overlap/idempotency constraints remain Phase 0 research items. |
| Complete accessible slices | PASS | Every story includes HTTP-level acceptance tests and Twig UI; DOM assertions plus one axe smoke scan provide repeatable accessibility validation. |
| Explicit contracts | PASS | The `/api/v1` contract is written in Phase 1 before controllers. Browser and API paths will share application services. |
| Small, operable, verified | PASS | One app and one database; no queue, Redis, SPA, or third-party SaaS. Docker, migrations, tests, lint, audit, readiness, and quickstart are required. |

No constitutional violation requires a complexity exception. Phase 0 research in
[research.md](./research.md) resolves all testing, accessibility, security, API,
concurrency, and data-integrity choices before Phase 1 design.

### Post-design re-check

| Gate | Result | Phase 1 evidence |
|---|---|---|
| Secure boundaries first | PASS | [data-model.md](./data-model.md) derives client ownership from authenticated principals; [openapi.yaml](./contracts/openapi.yaml) exposes header-only bearer auth and generic cross-client not-found behavior; [quickstart.md](./quickstart.md) includes a negative isolation scenario. |
| Data integrity and traceability | PASS | The model defines exclusion/unique/check constraints, optimistic versioning, immutable event rows, and atomic audit transaction boundaries. |
| Complete accessible slices | PASS | Quickstart exercises team, client, allowance, API, responsive keyboard, DOM, and axe validation paths without JavaScript-dependent core flows. |
| Explicit contracts | PASS | OpenAPI 3.1 specifies every public health/request operation, input, response, header, error, and security scheme before implementation. |
| Small, operable, verified | PASS | The design remains one Symfony app and PostgreSQL database; omitted mail, billing, files, queues, caches, realtime, and SPA layers remain explicit non-goals. |

All gates pass after design. There are no unresolved clarifications or complexity
exceptions.

## Project Structure

### Documentation (this feature)

```text
specs/001-partner-operations/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── openapi.yaml
└── tasks.md
```

### Source Code (repository root)

```text
assets/
└── styles/
    └── app.css
bin/
config/
migrations/
public/
src/
├── Controller/
│   ├── Api/
│   └── Web/
├── DataFixtures/
├── Entity/
├── Enum/
├── Form/
├── Repository/
├── Security/
│   └── Voter/
└── Service/
templates/
├── admin/
├── components/
├── dashboard/
├── request/
└── security/
tests/
├── Functional/
│   ├── Api/
│   └── Web/
├── Integration/
└── Unit/
compose.yaml
Dockerfile
```

**Structure Decision**: Use Symfony's standard single-application layout. Web and
API controllers are separated only at the HTTP adapter layer; both call the same
services, repositories, entities, validators, and security policies. This keeps
the Twig integration visible while preventing duplicate business rules.

## Complexity Tracking

No constitution violations or added infrastructure require justification.
