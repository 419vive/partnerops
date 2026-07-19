# Quality Gate Contract

## 1. Local black-box gate

### Interface

```text
npm run test:e2e:install
npm run test:e2e
```

### Preconditions

- Node.js 22 and dependencies from `npm ci`.
- PartnerOps responds successfully at `PLAYWRIGHT_BASE_URL` (default
  `http://127.0.0.1:8080`).
- A disposable database has current migrations and `AppFixtures` loaded.
- `cache.rate_limiter` has been cleared after the backend suite/fixture load.
- No production identity, credential, or dataset is used.

### Projects

| Project | Test selection | Contract |
|---|---|---|
| `desktop` | `tests/blackbox/browser.spec.cjs` | Chromium critical workflow and cross-client denial |
| `mobile` | `tests/blackbox/mobile.spec.cjs` | 320px responsive client journey and overflow check |
| `api` | `tests/blackbox/api.spec.cjs` | Creation, replay, conflict, isolation, and validation through HTTP |

All projects run with one worker, `fullyParallel: false`, and zero retries. Fixed
sleeps and CSS implementation selectors are forbidden.

### Exit and output

- Exit `0`: every selected scenario passes.
- Non-zero: assertion, setup, browser, timeout, or reporter failure.
- `playwright-report/`: HTML report for the run.
- `test-results/junit.xml`: JUnit report.
- `test-results/artifacts/`: trace, screenshot, and video retained on failure only.

Generated output is ignored by git. A raw failure trace can contain disposable
fixture credentials or cookies, so it must never be produced with production or
private data and remains subject to bounded artifact retention.

## 2. Pull-request quality gate

### Trigger

Existing `.github/workflows/ci.yml` on push to supported branches and every pull
request.

### Required ordering

1. Existing migration/schema, lint, PHPStan, PHPUnit, asset, dependency, OpenAPI,
   accessibility, and container checks remain intact.
2. Reset/migrate/load synthetic `AppFixtures`.
3. Clear `cache.rate_limiter` in `APP_ENV=test`.
4. Start and probe the test HTTP server.
5. Install the locked Chromium revision and run `npm run test:e2e`.
6. Upload Playwright HTML, JUnit, and any failure diagnostics with 14-day
   retention, including on failure.

The quality job fails when Playwright exits non-zero. Performance load is not part
of this blocking gate.

## 3. Manual performance gate

### Trigger

`.github/workflows/performance.yml` via `workflow_dispatch` on the commit selected
by the operator. No free-form workload inputs are exposed; the versioned profile
is the contract.

### Environment

- PostgreSQL 16 service with a disposable database.
- Current migrations plus `AppFixtures` and `PerformanceFixtures` (10,000 rows).
- Current production Docker image running Apache and passing `/health/ready`.
- `grafana/k6:2.1.0` container.

### Execution

1. `scripts/benchmark.sh` runs 30 samples for the authenticated dashboard and
   filtered queue with p95 <=750 ms.
2. `tests/load/partnerops.js` runs 10 virtual users for 60 seconds against the same
   pages. Login setup is excluded from named thresholds.
3. Named dashboard and filtered-queue p95 must be <1,500 ms and their HTTP failure
   rate <1%.

### Exit and output

- Any readiness, authentication, check, sequential threshold, load threshold, or
  artifact-generation failure makes the workflow non-zero.
- `test-results/benchmark.txt` records sequential output.
- `test-results/k6-summary.json` records machine-readable load metrics whenever
  the k6 step starts, including check or threshold failure.
- `test-results/application.log` records bounded container output on failure and
  contains no credentials or request bodies.
- A `performance-evidence-<run_id>-<run_attempt>` artifact uploads all evidence
  produced before success or failure with 30-day retention. Readiness or
  sequential failure can stop the workflow before a k6 summary exists.

Results identify the tested commit and GitHub runner and are not a production SLA
or capacity statement.

## 4. Defect intake contract

`.github/ISSUE_TEMPLATE/defect.yml` requires summary, severity, environment,
preconditions/steps, expected result, actual result, and evidence. Root cause,
fix, and regression coverage may be added as investigation progresses. Secrets,
tokens, production records, and private request bodies are prohibited.
