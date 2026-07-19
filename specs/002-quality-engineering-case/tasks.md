---

description: "Dependency-ordered implementation tasks for the PartnerOps Release Confidence Lab"
---

# Tasks: PartnerOps Release Confidence Lab

**Input**: Design documents from `/specs/002-quality-engineering-case/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/quality-gates.md`, `quickstart.md`

**Tests**: Automated tests are the requested feature. The Playwright and k6 tasks below implement the specified black-box quality gates; if they expose a product defect, preserve the failing scenario before changing application code.

**Organization**: Tasks are grouped by user story so browser, API, performance, and review evidence can be implemented and validated independently.

## Phase 1: Setup (Shared Tooling)

**Purpose**: Add only the exact test dependency and generated-output boundaries shared by every story.

- [x] T001 Add exact `@playwright/test` 1.61.1 plus `test:e2e` and `test:e2e:install` scripts, then refresh the lockfile in `package.json` and `package-lock.json`
- [x] T002 [P] Ignore generated Playwright HTML, JUnit, trace, screenshot, video, benchmark, and k6 output in `.gitignore`

---

## Phase 2: Foundational (Blocking Quality-Gate Infrastructure)

**Purpose**: Define the shared runner and deterministic CI lifecycle before adding story scenarios.

**⚠️ CRITICAL**: No user story work begins until the runner and CI environment are deterministic.

- [x] T003 Configure serial `desktop`, `mobile`, and `api` projects, HTML/JUnit reporters, failure-only diagnostics, base URL, and timeouts in `playwright.config.cjs`
- [x] T004 Update `.github/workflows/ci.yml` to clear `cache.rate_limiter` after fixture load, install locked Chromium, run `npm run test:e2e`, and always upload bounded Playwright/JUnit/failure artifacts without weakening existing gates

**Checkpoint**: A fixture-backed HTTP server can run one Playwright command with one worker, zero retries, and deterministic limiter state.

---

## Phase 3: User Story 1 - Protect the Critical Browser Workflow (Priority: P1) 🎯 MVP

**Goal**: Prove the real team workflow, tenant denial, and 320px responsive client journey through Chromium.

**Independent Test**: Reset and seed the disposable database, clear the limiter cache, start the app, then run `npx playwright test --project=desktop --project=mobile`; both projects must pass with no cross-tenant marker or horizontal document overflow.

### Browser automation for User Story 1

- [x] T005 [P] [US1] Implement semantic-locator login, request assignment, In Progress transition, internal update, and generic cross-client denial scenarios in `tests/blackbox/browser.spec.cjs`
- [x] T006 [P] [US1] Implement the authenticated 320px client navigation, request-detail, logout, and horizontal-overflow scenario in `tests/blackbox/mobile.spec.cjs`

**Checkpoint**: User Story 1 runs independently and produces an HTML/JUnit result plus failure-only diagnostics.

---

## Phase 4: User Story 2 - Verify the External API Contract (Priority: P1)

**Goal**: Prove request creation, exact replay, conflict, validation, and tenant isolation through the public HTTP contract.

**Independent Test**: On fresh fixtures and cleared limiter state, run `npx playwright test --project=api`; every response status/header/body assertion must pass without a browser page.

### API automation for User Story 2

- [x] T007 [US2] Implement authenticated creation, byte-identical idempotent replay, changed-payload `409`, Globex-to-Acme generic `404`, and invalid-body `422` scenarios in `tests/blackbox/api.spec.cjs`

**Checkpoint**: User Story 2 runs independently and demonstrates black-box parity with `specs/001-partner-operations/contracts/openapi.yaml`.

---

## Phase 5: User Story 3 - Measure Release Performance (Priority: P2)

**Goal**: Preserve reproducible sequential and concurrent evidence against 10,000 synthetic rows and the production Apache image.

**Independent Test**: Load both fixture groups, start the production image, run `scripts/benchmark.sh`, then run the pinned k6 profile; sequential p95, named load p95, checks, and failure-rate thresholds must all pass and emit machine-readable evidence.

### Performance automation for User Story 3

- [x] T008 [US3] Implement cookie-based login setup, tagged dashboard/filtered-queue requests, 10-VU 60-second options, checks, thresholds, and JSON summary behavior in `tests/load/partnerops.js`
- [x] T009 [US3] Add a read-only, manually dispatched PostgreSQL/production-container benchmark and k6 workflow with readiness failure handling, redacted logs, and 30-day artifacts in `.github/workflows/performance.yml`

**Checkpoint**: User Story 3 is independently triggerable and labels local/GitHub results as non-production evidence.

---

## Phase 6: User Story 4 - Review a Traceable QA Decision (Priority: P2)

**Goal**: Let a reviewer evaluate risk, execution, defect quality, and release judgment without first reading implementation code.

**Independent Test**: Follow the test-plan matrix, report evidence, historical commits, and defect form; every critical risk maps to a runnable scenario or explicit exclusion, and every result claim cites a real command or run.

### Review evidence for User Story 4

- [x] T010 [P] [US4] Write risk scope, environments, test data, entry/exit criteria, traceability matrix, and explicit exclusions in `docs/quality/test-plan.md`
- [x] T011 [P] [US4] Document the verified PostgreSQL `jsonb` idempotency replay impact, reproduction, root cause, fix commits, migration concern, and regression evidence in `docs/quality/defects/BUG-001-idempotency-replay.md`
- [x] T012 [P] [US4] Add required severity, environment, steps, expected/actual, evidence, and regression fields plus secret warnings in `.github/ISSUE_TEMPLATE/defect.yml`
- [ ] T013 [US4] Record freshly executed local and public results, limitations, known risks, evidence links, and Go/No-Go decision in `docs/quality/test-report.md`
- [ ] T014 [US4] Add a concise Release Confidence Lab section linking the plan, test plan, report, defect, commands, and public Actions evidence in `README.md`

**Checkpoint**: User Story 4 is independently reviewable and contains no invented metric, experience, tool, or discovery claim.

---

## Phase 7: Polish & Cross-Cutting Verification

**Purpose**: Correct prior documentation, verify the complete repository, publish public evidence, and close the Spec Kit record.

- [x] T015 Correct the overbroad authenticated-page/two-viewport axe statement and link the new black-box guide in `specs/001-partner-operations/quickstart.md`
- [ ] T016 Run the backend gate, Playwright projects, sequential benchmark, k6 profile, OpenAPI lint, axe login smoke, and container build from `specs/002-quality-engineering-case/quickstart.md` in a reproducible Linux workflow, then update only observed results in `docs/quality/test-report.md`
- [x] T017 Validate task format, requirement traceability, Markdown links, generated-output ignores, synthetic-data boundaries, credential/log redaction, and whitespace with `specs/002-quality-engineering-case/tasks.md`
- [ ] T018 Push `codex/002-quality-engineering-case`, run public quality and performance workflows, and add their exact URLs/results to `docs/quality/test-report.md` without describing shared-runner evidence as production capacity
- [ ] T019 Re-run the final commit's required GitHub Actions, set the implemented status, and mark completed checklist items in `specs/002-quality-engineering-case/spec.md` and `specs/002-quality-engineering-case/tasks.md`

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (Phase 1)**: Starts immediately.
- **Foundational (Phase 2)**: Depends on T001-T002 and blocks all user stories.
- **User Story 1 (Phase 3)**: Depends on T003-T004; no dependency on another story.
- **User Story 2 (Phase 4)**: Depends on T003-T004; no dependency on User Story 1.
- **User Story 3 (Phase 5)**: Depends on T001-T004 for shared repository setup; no dependency on browser/API scenario implementation.
- **User Story 4 (Phase 6)**: T010-T012 can start after planning; T013 requires observed runs from selected stories; T014 requires the final evidence paths.
- **Polish (Phase 7)**: Depends on every story selected for delivery; T018 requires a pushed branch, and T019 requires the final evidence update.

### User story dependency graph

```text
Setup -> Foundation -> US1 (browser) ----\
                    -> US2 (API) --------+-> US4 report/README -> Polish -> Publish
                    -> US3 (performance)-/
```

### Within each user story

- US1: T005 and T006 are parallel; validate both projects at the checkpoint.
- US2: T007 is a single black-box contract slice.
- US3: T008 precedes T009 because the workflow executes the versioned k6 script.
- US4: T010-T012 are parallel; T013 follows fresh runs; T014 follows final evidence paths.
- Any product defect exposed by Playwright/k6 must retain the failing scenario before an application fix.

### Parallel opportunities

- T002 can run beside T001 because it edits a different file.
- T005 and T006 can be implemented in parallel after the shared config exists.
- US1, US2, and T008 can proceed in parallel after Foundation because their files are disjoint.
- T010, T011, and T012 can proceed in parallel while automated scenarios are implemented.

---

## Parallel Examples

### User Story 1

```text
Task T005: Implement desktop critical workflow and tenant denial in tests/blackbox/browser.spec.cjs
Task T006: Implement 320px responsive client journey in tests/blackbox/mobile.spec.cjs
```

### User Stories 2-4

```text
Task T007: Implement API contract automation in tests/blackbox/api.spec.cjs
Task T008: Implement load profile in tests/load/partnerops.js
Task T010: Write traceable test plan in docs/quality/test-plan.md
Task T011: Write historical defect in docs/quality/defects/BUG-001-idempotency-replay.md
Task T012: Add defect intake form in .github/ISSUE_TEMPLATE/defect.yml
```

---

## Implementation Strategy

### MVP first

1. Complete Setup and Foundation (T001-T004).
2. Complete User Story 1 (T005-T006).
3. Stop and run the desktop/mobile independent test.
4. This is the smallest demonstrable Web and responsive-mobile regression case.

### Incremental delivery

1. Add US2 for external API risk evidence.
2. Add US3 as a manual, non-blocking performance profile.
3. Add US4 so a reviewer can understand scope, findings, and release judgment.
4. Run Polish and publish only after local and public evidence match.

### Team execution

After T001-T004, three agents may work on US1, US2/US3, and US4 in parallel
because their implementation files are disjoint. One integrator owns workflow
edits, final commands, evidence wording, and publication.

---

## Notes

- `[P]` means the task edits a different file and has no dependency on an unfinished task.
- `[US#]` maps directly to the four prioritized stories in `spec.md`.
- Generated HTML, JUnit, trace, screenshot, video, benchmark, k6, database, and log output is never committed.
- Mobile means responsive Web only; the case makes no Appium, native-app, game, years-of-experience, deployment, production-SLA, or full-WCAG claim.
- Commit after each verified logical group; do not mark a task complete from agent reports alone.
