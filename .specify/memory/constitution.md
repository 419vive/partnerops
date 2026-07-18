# PartnerOps Constitution

## Core Principles

### I. Secure Boundaries First (NON-NEGOTIABLE)
Every request MUST be authenticated or explicitly public. Client users may read
and mutate only records owned by their client account. State-changing browser
requests require CSRF protection; integration credentials are stored as hashes;
secrets and production data never enter the repository. Authorization is tested
at the HTTP boundary, including negative cross-client cases.

### II. Data Integrity and Traceability
Business state changes MUST pass through one validated application path and be
persisted transactionally. Request status, assignment, time usage, and budget
changes produce an immutable audit event. Database constraints protect required
relationships and uniqueness. Destructive deletion of business history is out
of scope; records are archived when removal is needed.

### III. Complete, Accessible Vertical Slices
Each user story ships end to end: route, authorization, validation, persistence,
Twig view, responsive HTML/CSS, and a runnable test. Core workflows MUST work
with keyboard navigation, visible focus, semantic HTML, labelled controls, and
without client-side JavaScript. JavaScript may enhance but never gate a primary
workflow.

### IV. Explicit Contracts
External JSON interfaces are versioned under `/api/v1`, use stable identifiers,
return consistent error objects, and are documented before implementation.
Browser forms and API endpoints share validation and application services so
business rules cannot drift. Contract-breaking changes require a new API version
or a documented migration.

### V. Small, Operable, and Verified
Prefer framework and platform capabilities over custom infrastructure. A single
Symfony application and one relational database are the default; extra services
require measured need. Every non-trivial branch leaves one focused automated
check. A release is complete only when migrations, tests, production asset build,
security audit, health check, and a clean install from documentation succeed.

## Technical and Product Constraints

- Runtime: PHP 8.3+, Symfony 7.4 LTS, Twig, Doctrine ORM, PostgreSQL 16.
- Presentation: server-rendered HTML, modern CSS, minimal vanilla JavaScript.
- Delivery: Docker Compose for local/production-like runs and GitHub Actions for
  repeatable verification.
- Scope: one consulting team managing multiple client accounts; no billing,
  payment processing, email delivery, file uploads, or real-time chat in v1.
- Privacy: logs MUST exclude passwords, raw API tokens, and private request bodies.
- Maintainability: controllers remain thin; shared business transitions live in
  application services, while Doctrine entities enforce local invariants.

## Development Workflow and Quality Gates

1. A feature specification and implementation plan precede application code.
2. Database migrations are reviewed with their entity changes and must work on a
   clean database as well as an existing seeded database.
3. Before merge: PHPUnit, Symfony config/container/Twig lint, Doctrine schema
   validation, Composer dependency audit, and the quickstart smoke path pass.
4. Security-sensitive code receives an explicit review for authentication,
   authorization, CSRF, validation, token handling, and data leakage.
5. Complexity exceptions are recorded in the plan with the rejected simpler
   alternative and a concrete removal condition.

## Governance

This constitution governs specifications, plans, implementation, and review.
Amendments require a written rationale, a version bump, and migration notes for
affected code or data. Reviews MUST cite any violation; unresolved violations
block completion. Patch versions clarify wording, minor versions add or expand a
principle, and major versions remove or redefine a principle.

**Version**: 1.0.0 | **Ratified**: 2026-07-18 | **Last Amended**: 2026-07-18
