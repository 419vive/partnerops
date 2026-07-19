# Quality Test Report — 2026-07-19

## Decision

**GO — the Release Confidence Lab is implemented and verified for commit
`8e3f801b1f09f08b6610be8bea5806a5ba40256c`.** The public quality and manual
performance workflows both completed successfully against that exact commit.
All applicable thresholds in [test-plan.md](./test-plan.md) passed, and no open
S1/S2 finding is recorded for the reviewed scope (`gh issue list` returned no
open issue on 2026-07-19).

This is a release decision for the tested QA case and synthetic PartnerOps
environment. It is not a production SLA, capacity certification, or native-app
test result.

## Scope and outcome

| Layer | Executed scope | Result |
|---|---:|---|
| PHPUnit / backend gate | 39 tests, 572 assertions | PASS |
| Playwright desktop | 2 Chromium scenarios | 2/2 PASS |
| Playwright responsive Web | 1 Chromium scenario at 320×800 | 1/1 PASS |
| Playwright API | 1 serial HTTP lifecycle | 1/1 PASS |
| Sequential performance | 2 pages × 30 samples after warm-up | PASS |
| Concurrent k6 | 2 pages, 10 VUs × 60 seconds | PASS |
| OpenAPI / PHPStan / dependency audits / migrations / container | Existing CI gates | PASS |
| Accessibility | Public `/login` axe smoke only | 0 violations |

The API runner test deliberately contains the related create, replay, conflict,
validation, and tenant-isolation assertions in one serial lifecycle. These
counts describe runner tests, not application coverage percentages.

## Public runtime evidence

| Environment | Commit / run | Observed result |
|---|---|---|
| Pre-feature public baseline | `5c855e8` · [run 29642823042](https://github.com/419vive/partnerops/actions/runs/29642823042) | Existing backend/CI gate passed before the lab was added |
| Merged feature quality gate | `51db3be` · [run 29686441287](https://github.com/419vive/partnerops/actions/runs/29686441287) | 39/572 backend and Playwright 4/4 passed after the first PR merge |
| Final quality gate | `8e3f801` · [run 29687395199](https://github.com/419vive/partnerops/actions/runs/29687395199) | 39/572, PHPStan, migrations, OpenAPI, dependency audits, login axe, production image/smoke, and Playwright 4/4 passed; Playwright reported 9.8 s (JUnit total 9.755168 s) |
| Final performance gate | `8e3f801` · [run 29687399527](https://github.com/419vive/partnerops/actions/runs/29687399527) | Production Apache image, 10,000-request synthetic PostgreSQL fixture, sequential benchmark, and full k6 profile passed |

### Sequential 30-sample benchmark

Observed on the GitHub-hosted Linux X64 runner at `2026-07-19T12:41:30Z`.

| Route | min | p50 | p95 | max | Gate |
|---|---:|---:|---:|---:|---|
| Dashboard | 33 ms | 34 ms | 37 ms | 39 ms | PASS (`p95 ≤ 750 ms`) |
| Filtered queue | 23 ms | 23 ms | 24 ms | 24 ms | PASS (`p95 ≤ 750 ms`) |

The login diagnostic recorded `POST 302 → /`, final HTTP `200` at `/`, no
authentication error, and an authenticated session established after the login
POST; the `authenticate` CSRF token itself is configured stateless.

### k6 10-VU / 60-second profile

| Route | p95 | HTTP failure rate | Check rate | Gates |
|---|---:|---:|---:|---|
| Dashboard | 199.83 ms | 0% | 100% | PASS (`p95 < 1,500 ms`, failures `< 1%`, checks `= 100%`) |
| Filtered queue | 135.65 ms | 0% | 100% | PASS (`p95 < 1,500 ms`, failures `< 1%`, checks `= 100%`) |

k6 completed 2,375 iterations and 4,771 HTTP requests with 10 maximum VUs; all
2,375 route requests per page returned without an HTTP failure, and both checks
per route passed. These are observed synthetic-run counts, not throughput or
capacity promises.

### Artifact provenance

- Performance artifact `performance-evidence-29687399527-1`, digest
  `sha256:7b6e65782245e882dd7d7968effa80e14a8b302a103a8b89a1bfeafc4b49a400`,
  contains `run-context.txt`, `benchmark.txt`, and `k6-summary.json` and expires
  after the configured 30-day retention window.
- Its run context records application image
  `sha256:8a8bacf4b541a51d1492dac5afbb5a31d7b3a36bbd70ea28cf35aaee139371b8`,
  pinned PostgreSQL image
  `sha256:92620daddcd947f8d5ab5ba66e848702fe443d87fed30c4cea8e389fd78dfc55`,
  and pinned k6 image
  `sha256:65c920dc067d5e2e00befbf982af6ad6ad0117034e8b1c65817c7975c52d4669`.
- Quality artifact `playwright-evidence-29687395199-1`, digest
  `sha256:e5a9e8990d1c7226e695d1ad55bd10ee3110a9389fbf1e5100baf3a231b0823b`,
  contains the HTML report and zero-failure JUnit XML under the configured
  14-day retention window.

Run pages are the durable citations; generated artifact download URLs expire and
are intentionally not committed.

## Findings and red-to-green history

| Evidence | Finding | Disposition |
|---|---|---|
| [run 29685381117](https://github.com/419vive/partnerops/actions/runs/29685381117) | Empty audit metadata serialized as JSON `[]`, violating the PostgreSQL object constraint during browser login | Product fix `44025f1` preserves an empty JSON object; later quality runs pass |
| [run 29685698614](https://github.com/419vive/partnerops/actions/runs/29685698614) | A broad textbox locator was ambiguous, and an implicit grid column expanded the 320 px document to 514 px | Test locator and CSS fix `b213309`; [run 29686040706](https://github.com/419vive/partnerops/actions/runs/29686040706) reached Playwright 4/4 |
| [run 29686451645](https://github.com/419vive/partnerops/actions/runs/29686451645) | The curl load client omitted same-origin evidence required by Symfony stateless CSRF; login failed before the benchmark | Added an explicit same-origin `Referer` while retaining production CSRF and Secure-cookie policy |
| [run 29687240168](https://github.com/419vive/partnerops/actions/runs/29687240168) | Sequential metrics passed, but k6 incorrectly required the initial login GET to create an anonymous session cookie and stopped before POST | Made the anonymous cookie optional and required the successful POST to establish the authenticated session; final run passed |

[BUG-001](./defects/BUG-001-idempotency-replay.md) remains a verified historical
S2 defect tied to the P1 API-idempotency risk and grounded in repository history.
The new Playwright suite does not claim it discovered that defect.

## Fresh local verification

The Apple Silicon workstation could not run the Intel-only Docker backend, so
application runtime claims above come only from public Linux runs. Local checks
still covered the harness itself:

- `npm ci --ignore-scripts`, `npm audit --audit-level=high`, OpenAPI lint, YAML,
  JavaScript, shell syntax, Markdown links, generated-output ignores, and
  `git diff --check` passed.

These repeatable checks validate static wiring; they do not replace the public
production-image runs. Ad hoc diagnostic harness output used during debugging was
not retained and is therefore not claimed as release evidence here.

## Known limitations

- Chromium is the only browser engine. The 320×800 case is responsive Web, not
  Appium, a native iOS/Android app, device-farm, game, or AltTester coverage.
- The axe gate scans only the public login page and is not a whole-site WCAG claim.
- Data is deterministic and synthetic; GitHub-hosted runner timing is
  non-production release evidence, not a production SLA or capacity result.
- The manual profile covers authenticated dashboard and filtered queue pages;
  it does not benchmark every endpoint, database size, region, browser, or
  failure mode.
