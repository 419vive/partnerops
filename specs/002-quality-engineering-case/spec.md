# Feature Specification: PartnerOps Release Confidence Lab

**Feature Branch**: `codex/002-quality-engineering-case`

**Created**: 2026-07-19

**Status**: Draft

**Input**: User description: "Create and publish a commercial-grade QA automation case tailored to a fully remote automation test engineer role, using Spec Kit planning/tasks and an agent team."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Protect the Critical Browser Workflow (Priority: P1)

A release owner runs one black-box browser command against deterministic demo
data and sees whether the highest-risk service workflow still works: a team member
logs in, opens an urgent client request, assigns it, moves it into progress, and
records an internal update. A client can use the same responsive Web product from
a mobile-sized viewport without gaining access to another client's work.

**Why this priority**: A release is unsafe if the core workflow breaks or one
client can see another client's data. This is the smallest useful browser
regression gate and directly demonstrates Web and responsive-mobile automation.

**Independent Test**: Rebuild and seed the disposable database, start the real
HTTP application, and run the desktop and mobile Playwright projects. The command
passes only when the complete team workflow succeeds and cross-client content is
absent from the client session.

**Acceptance Scenarios**:

1. **Given** the seeded urgent Acme request and an authenticated team member, **When** the member assigns the request, transitions it to In Progress, and adds an internal update, **Then** the request page and timeline show each persisted change.
2. **Given** an authenticated Acme client, **When** the client requests the seeded Globex URL directly, **Then** the server returns the generic not-found experience without Globex name, title, or private content.
3. **Given** a mobile Chromium viewport, **When** the Acme client logs in and opens their service request list and request detail, **Then** the primary navigation, request link, heading, and logout control remain operable without horizontal page overflow.
4. **Given** a browser assertion failure in CI, **When** the run completes, **Then** the CI artifact contains the HTML report plus the retained trace, screenshot, or video needed to reproduce the failure.

---

### User Story 2 - Verify the External API Contract (Priority: P1)

An integration owner runs API-level Playwright tests without a browser UI and
proves that request creation is authenticated, idempotent, client-scoped, and
consistent with the documented Problem Details contract.

**Why this priority**: Duplicate intake and cross-client leakage are direct
commercial risks. Testing through HTTP provides independent evidence beyond the
existing in-process PHPUnit suite.

**Independent Test**: Run only the Playwright API project against a freshly seeded
application. It creates a request, replays the exact call, changes the payload for
a conflict, retrieves with both client credentials, and submits invalid input.

**Acceptance Scenarios**:

1. **Given** a valid Acme fixture credential and a new idempotency key, **When** a valid request is posted, **Then** the API returns `201`, a stable request identifier, a `Location` header, and `Idempotent-Replayed: false`.
2. **Given** the same credential, key, and body, **When** the post is replayed, **Then** the API returns the byte-identical body and `Idempotent-Replayed: true` without a duplicate request.
3. **Given** the same credential and key but a different body, **When** the post is repeated, **Then** the API returns a documented `409` Problem Details response.
4. **Given** the Globex fixture credential, **When** it requests the Acme-created identifier, **Then** the API returns a generic `404` without Acme data.
5. **Given** a syntactically valid but invalid request body, **When** it is submitted with a fresh key, **Then** the API returns `422` and field-level validation evidence.

---

### User Story 3 - Measure Release Performance (Priority: P2)

A QA or operations engineer starts a manual GitHub Actions performance run against
the production container and a disposable PostgreSQL database containing 10,000
synthetic requests. The run exercises authenticated dashboard and filtered-queue
traffic with k6 and preserves the raw summary.

**Why this priority**: Performance must be measured under repeatable load, but
runner variance makes a concurrent load profile unsuitable as a blocking pull
request gate. A manual, versioned workflow keeps the evidence reproducible.

**Independent Test**: Trigger the performance workflow on a selected commit. It
creates the disposable dataset, starts the production image, runs the declared k6
profile, applies response-time and failure-rate thresholds, and uploads its JSON
summary even when a threshold fails.

**Acceptance Scenarios**:

1. **Given** 10,000 synthetic service requests and the production container, **When** the existing 30-sample sequential benchmark runs, **Then** dashboard and filtered-queue p95 remain at or below the existing 750 ms product target.
2. **Given** the same dataset and container, **When** 10 virtual users exercise the two pages for 60 seconds, **Then** each named page reports p95 below 1,500 ms and an HTTP failure rate below 1%.
3. **Given** a failed readiness check or sequential threshold, **When** the workflow ends, **Then** the run fails and retains run context, available benchmark output, and bounded application logs; a started k6 run additionally retains its JSON summary even when its checks or thresholds fail.
4. **Given** a completed performance run, **When** a reviewer opens the artifact, **Then** the commit, profile, environment limitations, thresholds, and raw metrics are identifiable.

---

### User Story 4 - Review a Traceable QA Decision (Priority: P2)

A hiring manager or engineering reviewer can inspect a concise test plan, current
execution report, and one real historical defect report to understand scope,
risk, evidence, release criteria, debugging, and known limitations without
running the project first.

**Why this priority**: Automation code alone does not demonstrate test planning,
defect communication, or release judgment, all of which are explicit role duties.

**Independent Test**: Follow every link in the quality documents and verify that
each risk maps to a runnable command or an explicit out-of-scope decision, each
reported result cites a real run, and the historical defect cites the commits that
introduced the regression test and forward migration fix.

**Acceptance Scenarios**:

1. **Given** the quality test plan, **When** a reviewer checks the traceability matrix, **Then** tenant isolation, critical workflow, API idempotency, responsive Web, and performance each map to a test and release criterion.
2. **Given** the execution report, **When** a reviewer checks a pass/fail or metric claim, **Then** it is backed by a dated local command or public GitHub Actions run and labels local/CI results accurately.
3. **Given** the historical idempotency defect report, **When** a reviewer follows its evidence, **Then** the PostgreSQL `jsonb` key-order failure, impact, root cause, fix, and regression coverage match repository history without claiming Playwright discovered it.
4. **Given** a newly found defect, **When** a contributor opens the repository issue form, **Then** severity, environment, steps, expected/actual result, evidence, and regression-test fields are requested.

### Edge Cases

- Browser and API tests run with one worker against disposable data so state-changing scenarios and retries cannot race each other.
- Test-generated identifiers and idempotency keys are unique within a run but do not expose secrets or production data.
- A failed browser test retains evidence; a successful run does not upload needless video or trace payloads.
- Mobile coverage means responsive Web in a mobile Chromium viewport, not a native mobile application or Appium coverage.
- The k6 profile excludes login setup from named business-page thresholds and fails clearly when authentication or readiness fails.
- Performance results are labelled as GitHub-hosted-runner or local Docker evidence, never as production capacity.
- Browser download or runner outages are infrastructure failures and remain distinguishable from product assertion failures.
- Existing PHPUnit, OpenAPI lint, accessibility smoke, migration, dependency, and container gates continue to run; Playwright does not replace them.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The repository MUST provide a pinned Playwright test runner using JavaScript and Chromium without adding a Page Object hierarchy or a second application.
- **FR-002**: The Playwright configuration MUST define separate desktop Web, mobile responsive Web, and API projects that can be run together or independently.
- **FR-003**: Browser tests MUST use role- or label-based selectors for user-visible actions and MUST exercise the real HTTP application with deterministic synthetic fixtures.
- **FR-004**: Browser regression MUST cover team login, request assignment, state transition, internal update, cross-client denial, and a mobile-sized client journey.
- **FR-005**: API regression MUST cover authenticated creation, byte-identical idempotent replay, conflicting replay, cross-client not-found behavior, and validation failure.
- **FR-006**: CI MUST run the Playwright regression after loading fixtures and MUST upload HTML, JUnit, and failure-diagnostic artifacts with bounded retention.
- **FR-007**: The pull request gate MUST keep performance load outside the blocking quality job; a separately triggerable workflow MUST run k6 against a production image and disposable PostgreSQL dataset.
- **FR-008**: The performance workflow MUST load the existing 10,000-request fixture and apply named dashboard and filtered-queue p95 and failure-rate thresholds.
- **FR-009**: The k6 run MUST export a machine-readable summary and preserve server logs on failure.
- **FR-010**: A concise test plan MUST document risks, scope, environments, test data, entry/exit criteria, traceability, and explicit non-goals.
- **FR-011**: A test report MUST record only freshly executed counts and metrics, distinguish local from CI evidence, and state a Go/No-Go decision plus known risks.
- **FR-012**: A historical defect report MUST cite verifiable repository commits and MUST distinguish when and how the defect was actually found from the new black-box suite.
- **FR-013**: A native GitHub issue form MUST capture actionable defect fields without adding an external test-management service.
- **FR-014**: Documentation MUST correct any existing claim that exceeds the commands actually run, including the current accessibility-scan scope.
- **FR-015**: All fixtures, credentials, reports, traces, and logs committed or uploaded by this feature MUST contain synthetic data only.
- **FR-016**: Existing backend, security, accessibility, contract, migration, dependency-audit, and container quality gates MUST remain green.

### Key Entities

- **Test Scenario**: A risk-linked, independently runnable browser, API, or performance behavior with preconditions, actions, expected outcomes, owner, and automation status.
- **Test Run**: One execution identified by commit, environment, start time, command/profile, scenario results, metrics, artifacts, and release decision.
- **Finding**: A defect record with severity, environment, reproducible steps, expected/actual behavior, evidence, root cause, fix reference, and regression coverage.
- **Quality Gate**: A command or workflow with entry conditions, pass/fail rules, timeout, and retained evidence.
- **Performance Profile**: A named k6 workload with dataset size, virtual users, duration, request tags, thresholds, and environment limitations.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On a fresh seeded environment, the complete Playwright suite finishes in under five minutes and reports zero failed scenarios across desktop, mobile, and API projects.
- **SC-002**: Automated black-box checks prove that an Acme browser or API credential receives no Globex name, title, description, or internal note when requesting a Globex-owned identifier.
- **SC-003**: Replaying one API creation with the same idempotency key produces an exact byte-for-byte body match and a changed replay header, while a changed payload produces `409`.
- **SC-004**: The mobile Chromium scenario completes the defined client journey at the configured viewport with no horizontal document overflow.
- **SC-005**: Over 10,000 synthetic requests, the 30-sample sequential benchmark reports p95 at or below 750 ms, while a separate 10-virtual-user, 60-second k6 load profile reports named-page p95 below 1,500 ms and HTTP failure rate below 1%.
- **SC-006**: Every Playwright CI run publishes JUnit plus an HTML report, and every failure includes at least one trace, screenshot, or video artifact; every k6 run publishes a JSON summary.
- **SC-007**: The test plan maps every critical business risk to an automated scenario or explicit exclusion, and the execution report contains no uncited pass count, timing, throughput, or capacity claim.
- **SC-008**: A clean checkout can reproduce the documented browser/API gate and manual performance profile without production credentials, private data, paid SaaS, or application changes outside the documented scope.

## Assumptions

- PartnerOps remains the system under test; this feature adds a black-box release-confidence layer rather than a separate portfolio application.
- Chromium provides the smallest credible browser matrix for this case. Firefox, WebKit, visual regression, and native app automation are deferred until a compatibility requirement or defect justifies them.
- Existing deterministic fixture accounts, fixture API tokens, OpenAPI contract, performance fixture, and 750 ms target remain the source of test data and thresholds.
- The main CI job uses the existing test-mode HTTP server for deterministic functional regression; the manual performance workflow uses the production container for realistic concurrency.
- GitHub Actions artifacts are ephemeral evidence. The repository keeps plans and dated summaries, not generated HTML reports, videos, traces, or raw production-like databases.
- The historical PostgreSQL idempotency replay defect is documented from commits `725f342` and `c4e794a`; the new suite does not claim authorship or discovery it cannot prove.
- No Selenium, Appium, AltTester, Allure, external test-management SaaS, AI self-healing, native app, or game automation is added because this Web/API product does not require it.
