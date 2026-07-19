# Implementation Plan: PartnerOps Release Confidence Lab

**Branch**: `codex/002-quality-engineering-case` | **Date**: 2026-07-19 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/002-quality-engineering-case/spec.md`

## Summary

Add a deliberately thin black-box QA layer to the existing PartnerOps product.
Pinned Playwright JavaScript tests cover the critical team workflow, responsive
client access, tenant isolation, and API idempotency through real HTTP. A manual
GitHub Actions workflow reuses the existing 10,000-row fixture, sequential
benchmark, and a pinned k6 container to record non-production performance
evidence. Native GitHub artifacts and an issue form cover reporting without a
test-management service, Page Object hierarchy, second application, or duplicated
business tests.

## Technical Context

**Language/Version**: Node.js 22 with CommonJS JavaScript for Playwright 1.61.1; k6 JavaScript on `grafana/k6:2.1.0`; existing PHP 8.4/Symfony 7.4 system under test

**Primary Dependencies**: Exact `@playwright/test` 1.61.1 dev dependency and its pinned Chromium build; pinned `grafana/k6:2.1.0` container; existing Docker Compose, PostgreSQL 16, PHPUnit, axe CLI, Redocly, and GitHub Actions

**Storage**: Existing disposable PostgreSQL test/demo database only; generated Playwright HTML/JUnit/trace files, benchmark text, k6 JSON, and container logs are ephemeral GitHub Actions artifacts

**Testing**: Playwright desktop/mobile/API black-box projects with one worker and zero retries; existing PHPUnit release gate and axe login smoke remain; existing `scripts/benchmark.sh` plus k6 provide sequential and concurrent performance evidence

**Target Platform**: Local macOS/Linux with Docker and Node.js 22; GitHub-hosted Ubuntu runners; Linux production Apache container for performance execution

**Project Type**: Quality-engineering feature inside one server-rendered Web application and versioned JSON API repository

**Performance Goals**: With 10,000 synthetic requests, 30-sample dashboard and filtered-queue p95 at or below 750 ms; under 10 virtual users for 60 seconds, named-page p95 below 1,500 ms and HTTP failure rate below 1%

**Constraints**: Synthetic data only; serial state-changing tests; semantic locators; no fixed sleeps; no product secrets in artifacts; API limiter cache cleared before black-box execution; load runs against Apache rather than the single-threaded PHP development server; performance evidence is not a production SLA

**Scale/Scope**: Four user stories; three small Playwright spec files, one k6 script, two workflows, one issue form, three quality documents, and no PHP/domain/schema changes

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Gate | Pre-design result | Evidence / design obligation |
|---|---|---|
| Secure boundaries first | PASS | Cross-client browser/API denial is P1; only committed synthetic fixture identities are used; no production secrets or bodies enter reports. |
| Data integrity and traceability | PASS | Tests call existing validated HTTP paths; shared state runs serially; idempotent replay checks exact response evidence without bypassing persistence. |
| Complete, accessible vertical slices | PASS | Desktop and narrow responsive workflows exercise the rendered Twig UI with label/role locators; the existing axe gate remains and its documented scope is corrected. |
| Explicit contracts | PASS | The existing OpenAPI contract is unchanged and tested as a black box; [quality-gates.md](./contracts/quality-gates.md) defines commands, exit rules, projects, and artifacts before implementation. |
| Small, operable, and verified | PASS | One Playwright dependency and one pinned k6 image reuse the application, fixtures, Compose, CI, and platform artifact/issue features; existing release gates stay intact. |

No constitutional violation or unresolved clarification blocks Phase 0. Playwright
is justified by the requested browser automation evidence; k6 is justified by the
requested concurrent pressure test. Neither adds an application runtime service.

### Post-design re-check

| Gate | Result | Phase 1 evidence |
|---|---|---|
| Secure boundaries first | PASS | [data-model.md](./data-model.md) restricts evidence to synthetic runs and requires redaction; [quality-gates.md](./contracts/quality-gates.md) makes tenant-denial assertions and rate-limit reset part of the gate. |
| Data integrity and traceability | PASS | The design serializes mutations, uses a fresh disposable database, records commit/environment on each run, and defines Finding/Test Run state transitions. |
| Complete, accessible vertical slices | PASS | [quickstart.md](./quickstart.md) validates desktop, 320px responsive Web, API, and current axe login scope without claiming native-app or full-site WCAG coverage. |
| Explicit contracts | PASS | Quality command inputs, project names, outputs, thresholds, artifact retention, and failure behavior are defined before code. The product OpenAPI remains the API oracle. |
| Small, operable, and verified | PASS | Design adds no Page Objects, SaaS, extra browser engines, application code, database entities, or duplicated backend suite. Every added behavior has one runnable gate. |

All gates pass after design. No complexity exception is required.

## Project Structure

### Documentation (this feature)

```text
specs/002-quality-engineering-case/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── quality-gates.md
└── tasks.md
```

### Source Code (repository root)

```text
.github/
├── ISSUE_TEMPLATE/
│   └── defect.yml
└── workflows/
    ├── ci.yml
    └── performance.yml
docs/
└── quality/
    ├── test-plan.md
    ├── test-report.md
    └── defects/
        └── BUG-001-idempotency-replay.md
tests/
├── blackbox/
│   ├── api.spec.cjs
│   ├── browser.spec.cjs
│   └── mobile.spec.cjs
└── load/
    └── partnerops.js
playwright.config.cjs
package.json
package-lock.json
README.md
```

**Structure Decision**: Keep the existing Symfony application untouched and place
external HTTP tests under `tests/blackbox`, load code under `tests/load`, and
human-readable evidence under `docs/quality`. One root Playwright config and the
existing package lock provide the whole JavaScript harness. GitHub-native workflow,
artifact, and issue-form capabilities cover automation and reporting.

## Complexity Tracking

No constitution violations or added runtime infrastructure require justification.
