# BUG-001: PostgreSQL replay response was not byte-identical

| Field | Value |
|---|---|
| Severity | S2 — High |
| Status | Verified and closed |
| Observed | 2026-07-18 while aligning the regression gate with PostgreSQL |
| Affected state | `idempotency_record.response_body` stored as PostgreSQL `jsonb` before forward migration `Version20260718000100` |
| Contract | Replaying the same credential, idempotency key, and validated payload within 24 hours returns the original `201` response body byte-for-byte |

## Discovery boundary

Repository history records this defect in commit
[`725f342`](https://github.com/419vive/partnerops/commit/725f3427468850d6e8c6a9bb8578acc580a5615e)
during PostgreSQL CI hardening. The exact-response assertion already existed in
the PHPUnit functional suite. The Release Confidence Lab and its Playwright tests
were added later: **Playwright did not discover this defect**.

## Reproduction

Use only a disposable PostgreSQL test database.

1. Apply `DoctrineMigrations\Version20260718000000` without the forward
   migration; its published schema stores `response_body` as `jsonb`.
2. Create an API request with a new `Idempotency-Key` and retain the raw `201`
   response body.
3. Load the stored idempotency response, or repeat the same API request with the
   same credential, key, and body.
4. Compare the replayed raw body with the first body.

The repository preserves a deterministic predecessor fixture in
[`scripts/prepare-idempotency-upgrade-fixture.php`](../../../scripts/prepare-idempotency-upgrade-fixture.php).
Before the forward migration, it verifies that the `jsonb` predecessor does not
preserve presenter key order.

## Expected result

The repeat call returns `201`, `Idempotent-Replayed: true`, and a response body
that is byte-identical to the first body. Exactly one service request, audit event,
and idempotency record exist.

## Actual result

PostgreSQL `jsonb` normalizes object key order. The replay contained the same JSON
values but serialized in a different order, so its bytes differed from the first
response. The repository evidence does not show a duplicate request or lost data;
the defect was a violation of the promised replay contract.

## Impact and root cause

Consumers comparing raw bodies, hashes, signatures, or cached responses could
reject a valid replay even though the JSON object was semantically equivalent.
The application stored the presenter array in `jsonb`, then serialized the
database-loaded array on replay. `jsonb` is appropriate for normalized querying,
but it does not preserve JSON object key order required by this contract.

## Fix and migration concern

Commit `725f342` changed the mapped response document to PostgreSQL `json`, which
preserves key order. Rewriting an already published initial migration would have
left deployed databases on different predecessor schemas, so commit
[`c4e794a`](https://github.com/419vive/partnerops/commit/c4e794a83a4536bb40627f0b34e0ec8ed161b03e)
restored the published initial migration and added
[`Version20260718000100`](../../../migrations/Version20260718000100.php).

The forward migration accepts both the original `jsonb` predecessor and the
briefly published `json` predecessor, reconstructs the response in presenter
order, and preserves nested pagination order and values. Its `ALTER TABLE` takes
an exclusive lock and rewrites retained replay rows, so release guidance calls
for pruning expired 24-hour records, applying it at low traffic, and completing
the migration before switching application traffic.

## Regression evidence

- [`RequestApiTest::testCreateAndReplayPersistExactlyOneRequestAuditAndIdempotencyRecord`](../../../tests/Functional/Api/RequestApiTest.php)
  repeats creation 20 times, requires exact raw-body equality, and asserts one
  request, audit event, and idempotency record.
- [`prepare-idempotency-upgrade-fixture.php`](../../../scripts/prepare-idempotency-upgrade-fixture.php)
  reproduces and identifies either `jsonb` or `json` predecessor state.
- [`simulate-published-json-predecessor.php`](../../../scripts/simulate-published-json-predecessor.php)
  and the CI migration jobs exercise both published histories.
- [`verify-migration-invariants.php`](../../../scripts/verify-migration-invariants.php)
  requires a final PostgreSQL `json` column and verifies top-level and nested key
  order plus exact cached values.
- Public baseline [CI run 29642823042](https://github.com/419vive/partnerops/actions/runs/29642823042)
  passed these migration and backend regression gates at commit `5c855e8`.
