# PartnerOps Data Model

This model implements the entities and rules in [spec.md](./spec.md). Internal
database identifiers are bigint primary keys. Browser and API URLs use Symfony
ULIDs (`CHAR(26)`) so public identifiers do not expose record counts.

## Shared conventions

- Instants use immutable UTC timestamps; Twig renders them in `Asia/Taipei`.
- Calendar allowance boundaries use PostgreSQL `DATE` and are inclusive.
- Emails and slugs are trimmed/lowercased before persistence and protected by
  unique indexes on `lower(value)`.
- Enum columns are strings plus database check constraints so SQL imports cannot
  introduce states the application does not understand.
- Business history is append-only. Comments, time entries, and audit events have
  no application update/delete operation.
- Foreign keys use `RESTRICT` for history-bearing parents. Client/user removal is
  represented by archive/deactivation rather than deletion.

## Client

Represents one customer organization served by the consulting team.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `name` | varchar(120) | Required, trimmed, 2–120 characters |
| `slug` | varchar(80) | Required, lowercase `a-z0-9-`, unique case-insensitively |
| `is_archived` | boolean | Default false |
| `archived_at` | UTC timestamp nullable | Required when archived |
| `created_at` | UTC timestamp | Immutable |
| `updated_at` | UTC timestamp | Changes with mutable fields |

Relationships: one client has many client users, service requests, allowance
periods, API credentials, and audit events.

Validation: an archived client cannot receive new users, requests, assignments,
time entries, allowance periods, or active API use. History remains administrator
readable.

## User

Represents one local authenticated administrator, team member, or client contact.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `email` | varchar(180) | Valid normalized email, unique case-insensitively |
| `password_hash` | varchar(255) | Symfony password hash; never exposed or audited |
| `display_name` | varchar(100) | Required, 2–100 characters |
| `role` | enum | `admin`, `agent`, or `client` |
| `client_id` | bigint nullable | Required only for `client`; forbidden otherwise |
| `is_active` | boolean | Default true |
| `deactivated_at` | UTC timestamp nullable | Required when inactive |
| `created_at` | UTC timestamp | Immutable |
| `updated_at` | UTC timestamp | Changes with mutable fields |

Relationships: a user may belong to one client and may request/own requests,
author comments/time entries, or act in audit events.

Validation: only active `admin`/`agent` users can be assigned work or log time.
Client users derive all ownership from `client_id`; forms and API input never
accept a client override.

## ServiceRequest

Represents one client-owned unit of work.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `client_id` | bigint | Required; immutable after creation |
| `requester_id` | bigint nullable | Browser origin; team user or same-client contact |
| `created_by_credential_id` | bigint nullable | API origin; active credential for the same client |
| `assignee_id` | bigint nullable | Active administrator/team member only |
| `title` | varchar(160) | Required, trimmed, 3–160 characters |
| `description` | text | Required, trimmed, 10–10,000 characters |
| `priority` | enum | `low`, `normal`, `high`, `urgent`; default `normal` |
| `status` | enum | See state machine below; default `new` |
| `due_at` | UTC timestamp nullable | Team-controlled; null means not scheduled |
| `resolved_at` | UTC timestamp nullable | Set while resolved, cleared on reopen |
| `closed_at` | UTC timestamp nullable | Set while closed, cleared on reopen |
| `version` | integer | Doctrine optimistic-lock version, starts at 1 |
| `created_at` | UTC timestamp | Immutable |
| `updated_at` | UTC timestamp | Updated with any mutable field |

Relationships: a request belongs to one client and exactly one origin—either a
requester user or an API credential—optionally one assignee, and has many comments,
time entries, audit events, and idempotency records. A database check constraint
enforces the origin XOR rule so integrations never impersonate a human user.

Indexes:

- `(client_id, status, created_at DESC)` for client queues.
- `(assignee_id, status, created_at DESC)` for personal queues.
- Partial `(due_at, priority)` where status is not `closed`/`resolved` for overdue
  and due-soon dashboard queries.
- `(status, priority, created_at)` for the team queue.

### Request state machine

```text
new ───────────────→ in_progress ───────→ resolved ───────→ closed
 │                       │   ↑               │                │
 ├─→ waiting_client ─────┘   └───────────────┘                │
 └─→ closed                   reopen                           │
                             in_progress ←─────────────────────┘
```

Allowed transitions:

| From | To | Notes |
|---|---|---|
| `new` | `in_progress`, `waiting_client`, `closed` | Team only |
| `in_progress` | `waiting_client`, `resolved` | Team only |
| `waiting_client` | `in_progress`, `resolved` | Team only |
| `resolved` | `in_progress`, `closed` | Reopen or finish |
| `closed` | `in_progress` | Explicit reopen |

Client users cannot change state, assignment, due date, or priority after creation.
Every transition is validated in one service and appends an audit event in the same
transaction. Submitting the same current state is rejected rather than silently
treated as a transition.

## Comment

Represents an immutable chronological discussion entry.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `service_request_id` | bigint | Required |
| `author_id` | bigint | Required, active user at creation |
| `body` | text | Required, trimmed, 1–5,000 characters |
| `is_internal` | boolean | Team-only option; forced false for client authors |
| `created_at` | UTC timestamp | Immutable |

Index: `(service_request_id, created_at ASC)`.

Client queries include `is_internal = false` in SQL before hydration. Audit metadata
records that a comment was added and whether it was internal, never its body.

## AllowancePeriod

Represents one included service-minute budget for a client date range.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `client_id` | bigint | Required |
| `starts_on` | date | Required |
| `ends_on` | date | Required, on/after start |
| `included_minutes` | integer | Required, 1–1,000,000 |
| `created_by_id` | bigint | Required administrator |
| `created_at` | UTC timestamp | Immutable |

PostgreSQL enables `btree_gist` and adds:

```text
EXCLUDE USING gist (
  client_id WITH =,
  daterange(starts_on, ends_on, '[]') WITH &&
)
```

This makes overlapping periods for the same client impossible under concurrency.
Application validation provides a friendly error before the database constraint.

Derived values (never stored):

- `approved_used_minutes = SUM(approved time_entry.minutes)`
- `remaining_minutes = MAX(included_minutes - approved_used_minutes, 0)`
- `overage_minutes = MAX(approved_used_minutes - included_minutes, 0)`
- `utilization_percent = approved_used_minutes / included_minutes * 100`

## TimeEntry

Represents immutable time logged against one request and allowance period.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `service_request_id` | bigint | Required |
| `allowance_period_id` | bigint | Required, same client and covers work date |
| `author_id` | bigint | Required active administrator/team member |
| `minutes` | integer | Required, 1–1,440 |
| `description` | varchar(500) | Required, 3–500 characters |
| `is_client_visible` | boolean | Default true |
| `approval_status` | enum | `approved` in v1; schema can add future states |
| `work_date` | date | Required, inside allowance period |
| `created_at` | UTC timestamp | Immutable |

Indexes: `(allowance_period_id, approval_status)` and
`(service_request_id, created_at DESC)`.

The selected allowance is derived from request client and work date. A submitted
allowance identifier is never trusted. Corrections require a future compensating
entry workflow; v1 intentionally exposes no edit/delete route.

## AuditEvent

Represents immutable allow-listed evidence of a business or security action.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `client_id` | bigint nullable | Null only for team-wide/security events |
| `actor_id` | bigint nullable | Null for anonymous/system activity |
| `actor_type` | enum | `user`, `api_credential`, `system`, `anonymous` |
| `action` | varchar(80) | Stable allow-listed event name |
| `subject_type` | varchar(60) | Stable entity label |
| `subject_public_id` | varchar(26) nullable | Public identifier only |
| `metadata` | jsonb | Allow-listed non-sensitive keys/values |
| `trace_id` | varchar(64) | Correlates audit and operational logs |
| `occurred_at` | UTC timestamp | Immutable |

Indexes: `(client_id, occurred_at DESC)`, `(action, occurred_at DESC)`, and
`(subject_type, subject_public_id, occurred_at)`.

A PostgreSQL trigger raises on update/delete. Metadata may include status, priority,
due date, changed field names, or assignee public ID. It cannot include password or
token material, headers/cookies, email submitted during failed login, descriptions,
comments, or request bodies.

## ApiCredential

Represents a revocable client-scoped machine credential.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `public_id` | ULID string | Required, unique, immutable |
| `client_id` | bigint | Required active client |
| `name` | varchar(100) | Required, unique per client among active credentials |
| `selector` | varchar(20) | Random public lookup selector, globally unique |
| `token_prefix` | varchar(24) | Non-secret display prefix only |
| `secret_hash` | char(64) | HMAC-SHA256 digest; never returned |
| `last_used_at` | UTC timestamp nullable | Updated at most once per hour |
| `revoked_at` | UTC timestamp nullable | Null while active |
| `created_by_id` | bigint | Required administrator |
| `created_at` | UTC timestamp | Immutable |

The full token is displayed only at creation. Authentication rejects revoked
credentials and credentials whose client has been archived.

## IdempotencyRecord

Represents a 24-hour API request reservation and replay result.

| Field | Type | Rules |
|---|---|---|
| `id` | bigint | Primary key |
| `api_credential_id` | bigint | Required |
| `idempotency_key` | varchar(128) | Required, visible ASCII |
| `request_fingerprint` | char(64) | SHA-256 of canonical validated input |
| `service_request_id` | bigint | Required once successful |
| `response_status` | smallint | `201` for v1 creation |
| `response_body` | jsonb | Exact safe response document for replay |
| `created_at` | UTC timestamp | Immutable |
| `expires_at` | UTC timestamp | Created time + 24 hours |

Constraints/indexes: unique `(api_credential_id, idempotency_key)` plus an index on
`expires_at` for bounded cleanup. A matching key/fingerprint returns the stored
response; a matching key/different fingerprint returns `409`; another credential
may independently use the same key.

## Transaction boundaries

- Request creation: request + initial audit + idempotency response (API path).
- Request mutation: optimistic lock + changed request + one audit event.
- Comment creation: comment + audit event.
- Time logging: applicable allowance lookup/lock + time entry + audit event.
- Client/user/allowance/credential lifecycle: changed row + audit event.

Any failure rolls back both the business row and its audit record. Read-only lists
do not write last-seen state; API credential `last_used_at` is a separate throttled
best-effort update and is not part of the business transaction.
