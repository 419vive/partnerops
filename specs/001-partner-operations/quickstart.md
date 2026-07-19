# PartnerOps Quickstart and Validation Guide

This guide proves the specification end to end. It intentionally references the
[data model](./data-model.md) and [OpenAPI contract](./contracts/openapi.yaml)
instead of duplicating implementation details.

For the external Playwright browser/API gate and manual load profile, follow the
[Release Confidence Lab quickstart](../002-quality-engineering-case/quickstart.md).

## Prerequisites

Recommended path:

- Docker Engine/Desktop 27+ with Compose v2
- `curl` and `jq`
- Node.js 22+ only for the axe accessibility smoke check

Host-runtime alternative:

- PHP 8.4 with `pdo_pgsql`, `intl`, `mbstring`, `openssl`, and `sodium`
- Composer 2.8+
- PostgreSQL 16

## 1. Configure a local environment

```bash
cp .env.example .env.local
```

The checked-in example contains only development placeholders. Never commit a
real `APP_SECRET`, `API_TOKEN_PEPPER`, database password, or production URL.

## 2. Start and seed the production-like stack

```bash
docker compose up --build -d db app
docker compose exec app php bin/console doctrine:database:drop --force --if-exists
docker compose exec app php bin/console doctrine:database:create
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:fixtures:load --group=AppFixtures --append --no-interaction
curl --fail http://localhost:8080/health/live
curl --fail http://localhost:8080/health/ready
```

Expected outcome: both probes return `200`; liveness reports `live` and readiness
reports `ready`. A stopped database leaves liveness at `200` and readiness at `503`.
The four database commands deliberately rebuild the disposable local `partnerops`
database on every run. Do not run the fixture command without `--append`: its
default purge issues `DELETE`, which the append-only audit trigger correctly
rejects. Never run this reset sequence against a production database.

Demo identities (synthetic local data only):

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@partnerops.test` | `PartnerOps!2026` |
| Team member | `agent@partnerops.test` | `PartnerOps!2026` |
| Acme client | `client@acme.test` | `PartnerOps!2026` |
| Globex client | `client@globex.test` | `PartnerOps!2026` |

Open <http://localhost:8080/login>. Do not reuse these credentials outside local
demo environments.

## 3. Validate the primary browser workflow

1. Sign in as the team member.
2. On the dashboard, open the urgent overdue Acme request.
3. Assign it to the current user, move it to **處理中**, and add an internal note.
4. Confirm the request timeline shows the assignment, transition, and note event,
   and that the overdue/unassigned dashboard counts change.

Expected outcome: the complete triage path takes under two minutes, uses regular
server-rendered forms, and remains keyboard-operable with visible focus.

## 4. Validate client isolation and visibility

1. Record the URL of one seeded Globex request, then sign out.
2. Sign in as the Acme client.
3. Create a request and add a client-visible comment.
4. Open the Acme request and confirm the team member's internal note is absent.
5. Request the recorded Globex URL directly.

Expected outcome: Acme sees only Acme records and client-visible discussion. The
Globex URL returns the same not-found screen as an unknown identifier and leaks no
client, title, or status details.

## 5. Validate allowance arithmetic

1. Sign in as the administrator and open Acme's current 20-hour allowance.
2. As a team member, add approved entries that bring usage below, exactly to, and
   beyond 1,200 included minutes.
3. Compare the request, client, team dashboard, and Acme client views.

Expected outcome: approved usage is identical everywhere; remaining time bottoms
at zero and overage is explicit. Client views include only client-visible entry
descriptions.

## 6. Validate the JSON contract and idempotency

The deterministic fixture token is safe only for this synthetic environment:

```text
ptk_demo01.k7s3P2mQ8vN5xR1aC9dF4gH6jL0wY2uB7eT8iO5pZ3A
```

Create a request:

```bash
curl --include --request POST http://localhost:8080/api/v1/requests \
  --header 'Authorization: Bearer ptk_demo01.k7s3P2mQ8vN5xR1aC9dF4gH6jL0wY2uB7eT8iO5pZ3A' \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: quickstart-order-duplicate-001' \
  --data '{"title":"結帳頁面出現重複訂單","description":"手機版送出後收到兩個不同訂單編號，請協助追查。","priority":"high"}'
```

Expected outcome: `201 Created`, a `Location` header, and a response matching
`RequestResource` in the OpenAPI document.

Run the identical command again. Expected outcome: the same status, public ID,
body, and location with `Idempotent-Replayed: true`, while the database still has
one request. Change the title but reuse the key; expect RFC 9457 `409` with code
`idempotency_conflict`.

Retrieve the public ID from `Location`:

```bash
curl --fail \
  --header 'Authorization: Bearer ptk_demo01.k7s3P2mQ8vN5xR1aC9dF4gH6jL0wY2uB7eT8iO5pZ3A' \
  http://localhost:8080/api/v1/requests/REPLACE_WITH_PUBLIC_ID | jq
```

Use the same ID with the Globex fixture token or an invalid token. Expected
outcome: generic `404` for the other client and generic `401` for invalid auth.

## 7. Run the release gate

```bash
docker compose exec app composer verify
npm ci
npm run a11y
docker compose build app
```

`composer verify` is the single backend gate and runs Composer validation/audit,
Symfony container/config/Twig/YAML lint, database migration/schema checks, and the
unit/integration/functional suite. The current axe command scans only the public
login page at its default viewport. It does not establish authenticated-page or
multi-viewport WCAG coverage; the feature 002 mobile Playwright scenario checks
responsive operability and overflow, not WCAG conformance.

Expected outcome: every command exits zero and the working tree remains unchanged.

## 8. Validate the target query scale

Run the isolated performance fixture only in a disposable local database:

```bash
docker compose exec app php bin/console doctrine:fixtures:load --group=performance --append --no-interaction
./scripts/benchmark.sh http://localhost:8080
```

Expected outcome: the fixture appends exactly 10,000 synthetic requests and is
safe to run again without duplicating them. The script records its machine/context
and reports dashboard and the first `in_progress` filtered-page server p95 at or
below 750 ms. If the threshold fails, capture PostgreSQL
`EXPLAIN (ANALYZE, BUFFERS)` before adding an index or cache.

## Cleanup

```bash
docker compose down --volumes
```

This removes the synthetic database volume. Production migrations and fixtures
are deliberately separate; fixture loading is never part of the production start.
