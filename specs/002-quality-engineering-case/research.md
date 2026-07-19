# Research: PartnerOps Release Confidence Lab

## Decision 1: Extend PartnerOps instead of creating a QA-only repository

**Decision**: Add the quality-engineering case to `419vive/partnerops` as feature
002.

**Rationale**: The existing public product supplies real multi-tenant, API,
idempotency, audit, database, Docker, and CI risks plus deterministic fixtures.
Keeping the tests with the system under test makes each commit reproducible and
avoids cross-repository version drift.

**Alternatives considered**: A separate QA showcase repository was rejected
because it would need to clone and pin PartnerOps, duplicate setup, and risk
testing a different revision. A new demo application was rejected because product
code would dilute the requested QA evidence.

## Decision 2: Use pinned Playwright with CommonJS JavaScript and Chromium

**Decision**: Add exact `@playwright/test` 1.61.1, `playwright.config.cjs`, and
three small `.cjs` specs for desktop, 320px responsive Web, and API execution.

**Rationale**: Playwright directly matches the role, supports browser and HTTP
testing in one runner, and works on the repository's Node.js 22 baseline. CommonJS
JavaScript requires no TypeScript compiler, module-mode change, or extra type
dependencies. Chromium is the smallest browser matrix that proves the requested
case; mobile coverage is explicitly responsive Web.

**Alternatives considered**: Selenium would add a second harness; Appium and
AltTester have no native app or game target; Python would add a second project
runtime; TypeScript would add type-checking setup for three short specs; Firefox
and WebKit are deferred until a compatibility requirement exists.

## Decision 3: Test only risks not already proven deeply by PHPUnit

**Decision**: Black-box coverage is limited to the critical team journey,
cross-tenant denial, mobile responsive navigation, and API creation/replay/
conflict/isolation/validation.

**Rationale**: The repository already has 39 PostgreSQL-backed unit, integration,
and functional tests. External HTTP tests add release-path evidence without
rewriting the same domain matrix. Semantic role and label locators reuse the
accessible HTML contract and avoid test-only selectors.

**Alternatives considered**: Porting all PHPUnit cases, a Page Object hierarchy,
visual snapshots, and arbitrary end-to-end coverage percentages were rejected as
duplication without a current risk.

## Decision 4: Reset the rate limiter before external API tests

**Decision**: After the backend suite and fixture load, CI clears
`cache.rate_limiter` in the test environment before starting Playwright.

**Rationale**: The existing authenticated limiter test can consume the fixture
credential's 120-request burst. Rebuilding the database does not clear Symfony's
limiter cache, so an otherwise valid black-box test can receive `429` based on
test order. Clearing the named disposable test pool fixes the shared root state;
retries would only hide it.

**Alternatives considered**: Playwright retries, different fixture tokens, and
waiting for limiter expiry were rejected as flaky or slower symptom workarounds.

## Decision 5: Keep performance manual and split sequential from concurrent goals

**Decision**: A `workflow_dispatch` performance workflow runs the existing
30-sample `scripts/benchmark.sh` and pinned `grafana/k6:2.1.0` against the
production Apache image with 10,000 synthetic requests. Sequential p95 remains
750 ms; concurrent 10-VU/60-second named-page p95 is provisionally 1,500 ms with
HTTP failures below 1%.

**Rationale**: The current CI HTTP server is single-threaded and unsuitable for
load. GitHub runner variance also makes concurrent timing a poor pull-request
gate. The production container provides realistic request concurrency, while a
manual public run preserves reproducible evidence without blocking normal work.

**Alternatives considered**: Playwright load, API load against the 120/min
credential limiter, a hosted performance SaaS, and PR-blocking shared-runner
thresholds were rejected. The API remains covered functionally; Web pages carry
the load profile.

## Decision 6: Reuse the existing axe smoke and correct its scope

**Decision**: Keep the current locked axe CLI login scan and correct the feature
001 quickstart statement that claims authenticated pages and two viewports.

**Rationale**: Accessibility is already a release gate, but the current command
only scans `/login`. Adding another axe integration is not necessary to satisfy
this QA role case. The new mobile Playwright test checks operability and overflow,
not WCAG conformance.

**Alternatives considered**: Replacing the working axe toolchain with
`@axe-core/playwright` or claiming full-site accessibility was rejected as extra
scope. Authenticated multi-page accessibility can be added when required.

## Decision 7: Use GitHub-native evidence and defect reporting

**Decision**: Upload Playwright HTML/JUnit/failure diagnostics and performance
text/JSON/logs with bounded retention. Add one GitHub issue form, a test plan, a
dated execution report, and one historical defect report.

**Rationale**: These native features demonstrate test planning, defect quality,
and release judgment without credentials, vendor lock-in, or another service.
Generated reports remain artifacts rather than committed build output.

**Alternatives considered**: Allure, TestRail, Jira, committed HTML/video, and a
custom report service were rejected because the repository has no measured need.

## Decision 8: Ground the defect case in repository history

**Decision**: Document the PostgreSQL `jsonb` idempotency replay key-order defect
using commits `725f342` and `c4e794a`, the current migration, and current
regression evidence.

**Rationale**: This is a real S2 defect tied to the P1 API-idempotency risk, with
an observable API impact, root cause, migration concern, and regression path.
The report will explicitly state that the new Playwright suite did not discover
the historical defect.

**Alternatives considered**: Inventing a demo defect, weakening the finding to a
template, or claiming discovery by a new tool was rejected as unverifiable.

## Decision 9: Preserve stateless CSRF semantics in non-browser load clients

**Decision**: Curl and k6 send an explicit same-origin `Referer` for login POSTs,
verify the success redirect, and require an authenticated session only after the
POST. The initial login GET, which uses the stateless `authenticate` CSRF token,
may legitimately return no session cookie.

**Rationale**: Public run `29686451645` showed `POST /login → /login` before any
measurement. `config/packages/csrf.yaml` marks `authenticate` stateless, and
Symfony's `SameOriginCsrfTokenManager` rejects a POST when Fetch Metadata,
Origin/Referer, and double-submit evidence are all absent. Chromium supplied the
browser headers; curl and k6 did not. After adding same-origin evidence, run
`29687240168` proved the sequential path but exposed k6's separate false
assumption that GET must create an anonymous session. Final run `29687399527`
executed both profiles successfully at commit `8e3f801`.

**Alternatives considered**: Disabling stateless CSRF, switching the production
image to `APP_ENV=test`, weakening Secure-cookie policy, hard-coding a session,
or accepting any `302` were rejected because each would lower security or hide
authentication failure instead of modeling the browser contract.

## Version and repository checks

- `npm view @playwright/test` returned version `1.61.1` with Node.js `>=18` on
  2026-07-19; the project already requires Node.js 22.
- GitHub release `grafana/k6` and Docker manifest checks both confirmed `v2.1.0`
  on 2026-07-19.
- PartnerOps `main` was clean at commit `5c855e8` before feature creation, and
  public CI run `29642823042` was the existing green baseline.

Planning unknowns were resolved before implementation; the runtime addendum above
records and closes the client-contract gap discovered only on the public Linux
production-image path.
