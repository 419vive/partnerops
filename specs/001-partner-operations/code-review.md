# Code Review Record

**Reviewed**: 2026-07-18
**Scope**: security, authorization, database integrity, API contract, performance,
production image, maintainability, accessibility, and specification coverage.

## Resolved findings

| Priority | Finding | Resolution |
|---|---|---|
| P1 | Well-formed invalid API tokens could avoid the anonymous limiter; cache-backed limiter updates could race across workers | Split missing/malformed, failed-auth, and authenticated credential paths; added sharded process locks and 30/120-limit regression tests |
| P1 | A credential revoked between authentication and write could still create a request | Re-fetch and pessimistically lock credential/client inside the creation transaction |
| P1 | Production `--no-dev` container could discover fixture classes whose parent bundle is absent | Excluded fixtures from production service discovery and registered them only in dev/test |
| P1 | Request title/description editing and assignment/status timeline were missing from the approved specification | Added optimistic-locked content editing, safe audit metadata, and a combined audit/comment timeline |
| P1 | Comment history was silently capped and the OpenAPI response had no pagination contract | Added retrievable Web/API comment pages plus `commentsPagination`; time history is also paginated |
| P1 | Duplicate active API credential names and concurrent allowance overlap could surface as PostgreSQL 500 responses | Added preflight validation and SQLSTATE-aware race handling |
| P1 | A browser session remained valid after its user was deactivated or client archived | Added security-aware user equality so Symfony invalidates refreshed session tokens immediately, with HTTP regression tests |
| P1 | CI combined an already suffixed database URL with Doctrine's `_test` suffix | Pointed CI at the base database name and verified the resolved test connection targets `partnerops_test` |
| P2 | Authentication success/failure/logout had no immutable audit evidence | Added a security event subscriber without recording submitted email, password, token, or request body |
| P2 | Request lists and detail histories triggered N+1 queries; client admin index used roughly `1 + 3N` queries | Added fetch joins, bounded pages, bulk aggregates, and query-count tests |
| P2 | Archived-client work polluted the global team queue and over-budget metric | Excluded archived clients only from unscoped operational views while retaining explicit history access |
| P2 | Readiness could wait on an unbounded database call; API 500 logs lacked actionable location/trace | Added PostgreSQL statement/connect timeouts and safe file/line/stack diagnostics |
| P2 | Fixture reload conflicted with the append-only audit trigger; SchemaTool tests did not exercise production-only constraints | Documented a rebuild-and-append demo flow and added a migration-backed CI invariant gate |
| P2 | Reverse-proxy trust and production cookie behavior were implicit | Restricted trusted forwarded headers, forced production Secure cookies, and documented exact edge CIDR handling |

No finding was suppressed with a static-analysis baseline or ignore annotation.

## Local quality gates

- PHPUnit: 38 tests, 561 assertions
- PHPStan: level 8, zero errors
- Symfony container, YAML, and Twig lint: pass
- Composer validation and locked dependency audit: pass
- npm high-severity audit: pass
- Redocly OpenAPI recommended rules: pass
- axe-core login smoke: zero WCAG 2 A/AA violations
- Production cache warmup and asset compilation: pass

PostgreSQL migration invariants and the non-root production image/runtime smoke
are intentionally repeated in GitHub Actions on Linux.
