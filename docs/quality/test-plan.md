# Quality Test Plan

PartnerOps Release Confidence Lab adds a thin black-box release gate to the
existing Symfony application. It tests the commercial risks that are most
visible at the deployed HTTP boundary without duplicating the existing PHPUnit
domain suite.

Execution results and release decisions belong in [test-report.md](./test-report.md).
This plan defines coverage; it does not claim that a scenario has passed.

## Risk scope

| Risk | Priority | Release consequence |
|---|---:|---|
| A team member cannot complete the core request-triage workflow | P1 | Client work cannot be assigned or progressed reliably |
| A client can discover another tenant's request or private content | P1 | Confidentiality breach |
| API retries create inconsistent responses or accept conflicting payloads | P1 | Integration errors, duplicate-processing risk, and broken client contracts |
| The responsive Web workflow is unusable at 320px | P2 | Mobile-sized browser users cannot complete supported work |
| Dashboard or filtered-queue latency regresses at the target dataset | P2 | Operational triage slows as request volume grows |

## Environments and data

| Environment | Purpose | Runtime |
|---|---|---|
| `local-docker` | Reproduce browser, API, sequential benchmark, and load scenarios | Docker Compose, PostgreSQL 16, Node.js 22, pinned Chromium |
| `github-quality` | Blocking backend and Playwright regression | GitHub-hosted Ubuntu runner, PostgreSQL 16 service, test HTTP server |
| `github-performance` | Manually triggered, non-blocking performance evidence | GitHub-hosted Ubuntu runner, production Apache image, PostgreSQL 16, pinned k6 image |

Browser scenarios use deterministic `AppFixtures`; the API scenario adds one
uniquely keyed synthetic request. Performance runs append `PerformanceFixtures`,
producing exactly 10,000 synthetic service requests. Production identities,
credentials, records, and private request bodies are prohibited. Raw Playwright
failure traces can contain
the disposable fixture password, token, or session cookie, so their retention is
bounded and they are never copied into committed reports.

## Entry criteria

- The tested commit and environment are recorded.
- Dependencies come from the committed Composer and npm lockfiles.
- A disposable database is on current migrations and contains `AppFixtures`.
- `/health/ready` succeeds and `cache.rate_limiter` has been cleared after
  fixture loading.
- The performance profile additionally contains `PerformanceFixtures` and runs
  against the production Apache image, not the single-threaded PHP test server.
- Failure artifacts contain only disposable synthetic fixture data, have bounded
  retention, and contain no production credential, private record, or request body.

## Traceability

| ID | Risk and requirement | Automated evidence | Pass oracle | Release rule |
|---|---|---|---|---|
| `QE-001` | P1 critical workflow; 002 FR-003/FR-004 | `tests/blackbox/browser.spec.cjs` (`desktop`) | Login, assignment, In Progress transition, internal update, and timeline evidence persist | Required in the blocking quality gate |
| `QE-002` | P1 browser tenant isolation; 001 FR-002, 002 FR-004 | `tests/blackbox/browser.spec.cjs` (`desktop`) | Acme receives generic not-found behavior and no Globex name, title, or private content | Required in the blocking quality gate |
| `QE-003` | P2 responsive Web; 002 FR-002/FR-004 | `tests/blackbox/mobile.spec.cjs` (`mobile`) | At 320px, client navigation, list, detail, and logout remain operable with no horizontal document overflow | Required in the blocking quality gate |
| `QE-004` | P1 API idempotency; 001 FR-016, 002 FR-005 | `tests/blackbox/api.spec.cjs` (`api`) | Initial `201`, byte-identical replay with changed replay header, and changed-payload `409` | Required in the blocking quality gate |
| `QE-005` | P1 API tenant isolation; 001 FR-002, 002 FR-005 | `tests/blackbox/api.spec.cjs` (`api`) | Globex credential receives generic `404` for an Acme identifier with no Acme content | Required in the blocking quality gate |
| `QE-006` | P2 API validation contract; 001 FR-016, 002 FR-005 | `tests/blackbox/api.spec.cjs` (`api`) | Invalid input returns documented `422` field evidence | Required in the blocking quality gate |
| `QE-007` | P2 sequential performance; 001 SC-008, 002 FR-008 | `scripts/benchmark.sh` | With 10,000 synthetic requests, 30-sample dashboard and filtered-queue p95 are each at most 750 ms | Required when issuing a performance-reviewed decision |
| `QE-008` | P2 concurrent performance; 002 FR-007/FR-008/FR-009 | `tests/load/partnerops.js` | At 10 virtual users for 60 seconds, each named-page p95 is below 1,500 ms and HTTP failure rate is below 1% | Manual gate; a skipped run cannot be reported as passing |

The existing OpenAPI document remains the API contract. Existing PHPUnit,
migration, schema, PHPStan, dependency, container, Redocly, and axe gates remain
independent evidence rather than being reimplemented in Playwright.

## Exit and release criteria

A `GO` decision requires:

- zero failed P1 scenarios and no open S1/S2 finding affecting the release;
- every selected Playwright project to exit zero with HTML and JUnit evidence;
- failure diagnostics to be retained when a browser assertion fails;
- every applicable performance threshold to pass; and
- the dated test report to cite the tested commit, commands, environment, and
  public run or retained artifact for every count or metric.

A P1 failure is `NO-GO`. Infrastructure failure, an omitted manual performance
run, or an unresolved P2 result is reported as `CONDITIONAL` with the limitation;
it is not converted into a pass.

## Explicit exclusions

- Responsive Web coverage is not native mobile, Appium, AltTester, or game testing.
- Firefox, WebKit, visual regression, and a Page Object hierarchy are deferred
  until a compatibility or maintainability need is demonstrated.
- Playwright does not replace the existing 39-test backend suite or claim an
  arbitrary coverage percentage.
- The current axe command scans the public login page only. Mobile Playwright
  checks operability and overflow, not authenticated-page or full-site WCAG
  conformance.
- Shared-runner and local Docker timings are reproducible test evidence, not a
  production SLA, capacity statement, or penetration test.
- Generated HTML, JUnit, traces, screenshots, videos, benchmark output, k6 JSON,
  and server logs remain bounded CI artifacts or ignored local output.
