# Data Model: PartnerOps Release Confidence Lab

This feature adds no production database entity. The following evidence entities
are represented by versioned Markdown, test definitions, workflow metadata, and
ephemeral CI artifacts.

## Test Scenario

| Field | Type | Rules |
|---|---|---|
| `id` | string | Stable `QE-###`; unique in the test plan |
| `risk` | enum | `security`, `workflow`, `contract`, `responsive-web`, `performance` |
| `priority` | enum | `P1` or `P2` for this feature |
| `layer` | enum | `browser`, `api`, `sequential-performance`, `load` |
| `project` | string | Playwright project or workflow/profile name |
| `preconditions` | list | Disposable environment, fixture, identity, and readiness requirements |
| `oracle` | list | Observable status, headers, body/DOM exclusions, metrics, and thresholds |
| `automationPath` | path | Repository-relative runnable spec or script |
| `status` | enum | `planned`, `automated`, `passing`, `failing`, `excluded` |
| `evidence` | list | Test Run or Finding references; empty while only planned |

**Relationships**: A Test Scenario appears in many Test Runs and may produce many
Findings. Every P1 risk maps to at least one automated scenario.

## Test Run

| Field | Type | Rules |
|---|---|---|
| `commitSha` | git SHA | Required; exact tested revision |
| `environment` | enum | `local-docker`, `github-quality`, `github-performance` |
| `startedAt` | RFC 3339 instant | Required; UTC in machine output, timezone stated in human report |
| `dataset` | string | Fixture group and row count; synthetic only |
| `commandOrProfile` | string | Exact command or fixed workflow profile |
| `scenarioResults` | map | Passed, failed, skipped counts; no unsupported aggregate |
| `metrics` | map | Named p50/p95/max/failure metrics when applicable |
| `artifacts` | list | GitHub run/artifact URLs or local file references |
| `decision` | enum | `GO`, `NO-GO`, `CONDITIONAL` |
| `limitations` | list | Runner, browser, dataset, and non-production scope |

**Validation**: A `GO` run has zero failed P1 scenarios and all applicable
thresholds pass. Every numeric claim has command output or a public run reference.

## Finding

| Field | Type | Rules |
|---|---|---|
| `id` | string | Stable `BUG-###` |
| `title` | string | Observable failure, not presumed cause |
| `severity` | enum | `S1-critical`, `S2-high`, `S3-medium`, `S4-low` |
| `status` | enum | `new`, `confirmed`, `fixed`, `verified`, `closed` |
| `environment` | string | Commit, runtime, database/browser, and dataset |
| `preconditions` | list | Minimum required state |
| `steps` | ordered list | Deterministic reproduction |
| `expected` / `actual` | string | Distinct and observable |
| `evidence` | list | Logs, responses, screenshots, traces, commits; synthetic/redacted |
| `impact` | string | User or business consequence |
| `rootCause` | string | Optional until confirmed |
| `fix` | git reference | Optional until fixed |
| `regressionCoverage` | path/list | Required before verified |

**State transition**:

```text
new -> confirmed -> fixed -> verified -> closed
  \-> closed (duplicate/not reproducible, with reason)
confirmed -> new (evidence disproves original reproduction)
fixed -> confirmed (verification fails)
```

## Quality Gate

| Field | Type | Rules |
|---|---|---|
| `name` | string | Stable command/workflow name |
| `trigger` | enum | `local`, `push`, `pull_request`, `workflow_dispatch` |
| `entryConditions` | list | Dependencies, ready endpoint, fixture and cache state |
| `command` | string | Exact runnable interface |
| `passRules` | list | Exit zero plus named behavior/threshold criteria |
| `timeout` | duration | Bounded in workflow/config |
| `outputs` | list | Artifact paths and formats |
| `retentionDays` | integer | Positive and bounded |

**Relationships**: A Quality Gate runs many Test Scenarios and creates one Test
Run per execution.

## Performance Profile

| Field | Type | Rules |
|---|---|---|
| `name` | enum | `sequential-baseline`, `concurrent-load` |
| `datasetSize` | integer | Exactly 10,000 synthetic performance requests plus base fixtures |
| `target` | enum | `dashboard`, `filtered_queue` |
| `virtualUsers` | integer | 10 for concurrent load; N/A for sequential baseline |
| `duration` | duration | 60 seconds for concurrent load |
| `samples` | integer | 30 per target for sequential baseline |
| `thresholds` | map | Sequential p95 <=750 ms; load p95 <1500 ms and failure rate <1% |
| `excludedTraffic` | list | Login setup and readiness do not contribute to named-page timing |
| `limitations` | list | GitHub-hosted runner/local Docker; not production capacity or SLA |

## Evidence safety rules

- Only `AppFixtures`, `PerformanceFixtures`, and test-generated synthetic data may
  enter committed examples or uploaded artifacts.
- Raw Playwright failure traces can contain disposable synthetic fixture
  credentials, cookies, or form values. They remain bounded CI artifacts and are
  never copied into committed reports; production or private values are prohibited.
- Generated browser reports, traces, videos, screenshots, k6 JSON, benchmark text,
  and container logs remain ignored local output or bounded CI artifacts.
