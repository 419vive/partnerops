# Quickstart: Validate the Release Confidence Lab

This guide validates the feature end to end without duplicating implementation
code. The command and artifact guarantees are defined in
[quality-gates.md](./contracts/quality-gates.md); evidence entities and safety
rules are in [data-model.md](./data-model.md).

## Prerequisites

- Docker Engine/Desktop with Compose v2
- Node.js 22 and npm
- A clean disposable PartnerOps database; never point these commands at production

## 1. Start and seed the system under test

From the repository root:

```bash
docker compose up --build -d --wait db app
docker compose exec app php bin/console doctrine:database:drop --force --if-exists
docker compose exec app php bin/console doctrine:database:create
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --group=AppFixtures --append --no-interaction
docker compose exec app php bin/console cache:pool:clear cache.rate_limiter
curl --fail http://127.0.0.1:8080/health/ready
```

Expected outcome: readiness returns `{"status":"ready"}` and only synthetic demo
data exists. Database drop and fixtures are destructive and are permitted only on
this disposable local Compose database.

## 2. Install the exact browser test toolchain

```bash
npm ci
npm run test:e2e:install
```

Expected outcome: npm uses `package-lock.json`, and Playwright installs its locked
Chromium revision plus required local dependencies.

## 3. Run all black-box projects

```bash
npm run test:e2e
```

Expected outcome: desktop, mobile responsive Web, and API projects all pass with
one worker and no retries. The HTML report is under `playwright-report/`; JUnit
and failure-only diagnostics are under `test-results/`.

Run one independently testable project when diagnosing:

The desktop project changes the fixed seeded request. Repeat step 1's database
reset, migration, fixture load, and limiter clear before rerunning it against the
same environment.

```bash
npx playwright test --project=desktop
npx playwright test --project=mobile
npx playwright test --project=api
```

## 4. Run the local performance profile

Append the isolated dataset once:

```bash
docker compose exec app php bin/console doctrine:fixtures:load --group=performance --append --no-interaction
./scripts/benchmark.sh http://127.0.0.1:8080
docker run --rm --network partnerops_default \
  --volume "$PWD/tests/load:/tests:ro" \
  --env BASE_URL=http://app:8080 \
  grafana/k6:2.1.0@sha256:65c920dc067d5e2e00befbf982af6ad6ad0117034e8b1c65817c7975c52d4669 \
  run /tests/partnerops.js
```

Expected outcome: the sequential product target and versioned concurrent
thresholds pass. This is a local Docker baseline, not production capacity.

## 5. Run the public performance workflow

The workflow now exists on the default branch. Trigger it for the branch or tag
under review (GitHub requires the workflow definition itself to remain on the
default branch), then verify the resulting run's exact commit before citing it:

```bash
SELECTED_REF=your-branch-or-tag
EXPECTED_SHA=$(git rev-parse "$SELECTED_REF^{commit}")
gh workflow run performance.yml --ref "$SELECTED_REF"
gh run list --workflow performance.yml --branch "$SELECTED_REF" \
  --event workflow_dispatch --limit 10 \
  --json databaseId,headSha,url,createdAt,status,conclusion
printf 'Expected headSha: %s\n' "$EXPECTED_SHA"
```

Choose the newly created row whose `headSha` equals `EXPECTED_SHA`; do not infer
identity from `--limit 1` alone. A successful run builds that commit's production
container, loads 10,000 synthetic requests, executes both profiles, and uploads
run context, benchmark text, and k6 JSON as a 30-day artifact. `application.log`
is failure-only, and an early failure may omit downstream benchmark or k6 files.
The verified reference execution is
[run 29687399527](https://github.com/419vive/partnerops/actions/runs/29687399527);
use the report rather than an expiring artifact URL for durable evidence.

## 6. Validate the complete repository gate

```bash
docker compose exec app composer verify
npm run api:lint
npm run a11y
docker compose build app
```

Expected outcome: existing backend, contract, login accessibility smoke, and
container gates remain green. The axe command currently scans the public login
page only; mobile Playwright proves responsive operability, not full WCAG
conformance.

## 7. Review human-readable evidence

- Test scope and traceability: `docs/quality/test-plan.md`
- Dated results and release decision: `docs/quality/test-report.md`
- Grounded historical defect: `docs/quality/defects/BUG-001-idempotency-replay.md`
- New-finding intake: GitHub's **New issue → Defect report** form

Every numeric claim in the report must cite fresh command output or a public
Actions run. Do not copy generated HTML, trace, video, JSON, or databases into git.

## Cleanup

```bash
docker compose down --volumes
```

Expected outcome: the disposable database and containers are removed; source and
versioned quality documents remain.
