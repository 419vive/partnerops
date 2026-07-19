# Quality Test Report — 2026-07-19

## Decision

**CONDITIONAL — runtime evidence pending.** The quality harness is implemented and
its local dependency, syntax, discovery, OpenAPI, YAML, and whitespace checks pass.
This machine cannot execute the Docker-backed application because its installed
Docker Desktop backend is Intel-only on Apple Silicon. Browser/API behavior and
the performance profile therefore remain unverified until the public Linux
workflows run; this is an environment limitation, not a converted pass.

The release criteria and scenario ownership are defined in
[test-plan.md](./test-plan.md). Generated output is not committed.

## Scope under review

| Layer | Runner tests / profile | Current evidence |
|---|---:|---|
| Playwright desktop | 2 | Discovered; runtime pending |
| Playwright mobile | 1 | Discovered; runtime pending |
| Playwright API | 1 | Discovered; runtime pending |
| Sequential performance | 2 named pages × 30 samples | Pending public production-container run |
| Concurrent k6 | 2 named pages, 10 VUs × 60 seconds | Pending public production-container run |

The single API test deliberately performs the related create/replay/conflict/
isolation/validation assertions in one serial lifecycle. Counts describe runner
tests, not the number of assertions or an application coverage percentage.

## Fresh local checks

Executed from the repository root on 2026-07-19 (Asia/Taipei):

| Command | Observed result |
|---|---|
| `npm ci --ignore-scripts` | 154 packages installed; audit examined 155 packages |
| `npm audit --audit-level=high` | 0 vulnerabilities |
| `node --check` on Playwright config, three black-box specs, and k6 script | Exit 0 for all five files |
| `npm run test:e2e -- --list --reporter=line` | 4 tests discovered in 3 files across `desktop`, `mobile`, and `api` |
| `npm run api:lint` | OpenAPI description valid |
| Ruby YAML parse for both workflows and defect form | Parse succeeded |
| `docker compose config --quiet` | Pinned Compose configuration valid |
| actionlint 1.7.12 plus offline zizmor | Both workflows valid; zizmor reported no findings |
| Relative Markdown links, T001–T019 sequence, and generated-output ignores | All checks passed |
| `git diff --check` | No whitespace errors |

These commands prove installability and static wiring only. They do not prove a
browser assertion, API response, database invariant, image build, or timing target.

## Runtime evidence

| Environment | Commit / run | Result | Evidence |
|---|---|---|---|
| Existing public baseline | `5c855e8` | PASS for the pre-feature backend/CI gate | [GitHub Actions run 29642823042](https://github.com/419vive/partnerops/actions/runs/29642823042) |
| Feature quality workflow | Not run yet | PENDING | Will be recorded after branch push |
| Feature performance workflow | Not run yet | PENDING | Workflow must first exist on the default branch |

The baseline run contains the existing 39 PHPUnit tests / 572 assertions,
PHPStan, migrations, OpenAPI, login-page axe smoke, dependency audit, production
image build, and runtime smoke. It does **not** prove the new Playwright or k6
feature and is not presented as such.

## Findings

- No new product finding is opened from static checks.
- [BUG-001](./defects/BUG-001-idempotency-replay.md) is a verified historical
  defect used to demonstrate reproduction, root-cause, migration, and regression
  reporting. Playwright did not discover it.

## Known limitations and next decision

- Chromium is the only browser engine in this case; mobile means 320px responsive
  Web, not native-app automation.
- The current axe gate covers the public login page only.
- GitHub-hosted and local Docker timings are non-production evidence, never a
  production SLA or capacity statement.
- A `GO` decision requires a green feature quality run plus a completed manual
  performance run meeting the thresholds in the test plan.

This report will be updated with exact tested commits, public run URLs, observed
counts, metrics, artifacts, and the final Go/No-Go decision after those workflows
finish.
