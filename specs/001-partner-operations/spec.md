# Feature Specification: Partner Service Operations Portal

**Feature Branch**: `001-partner-operations`

**Created**: 2026-07-18

**Status**: Implemented and locally verified

**Input**: User description: "Build a commercial-level project using backend development, HTML, CSS, Twig templates, database operations, integration, debugging, and maintainable system design for a long-term consulting partnership role."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Triage and Deliver Client Requests (Priority: P1)

An authenticated team member sees the open workload, creates or opens a service
request, assigns an owner, changes priority and workflow state, sets a due date,
and adds progress notes. The dashboard highlights overdue and at-risk work.

**Why this priority**: Request ownership and visible delivery status are the
smallest complete product that replaces an error-prone chat-and-spreadsheet flow.

**Independent Test**: Seed one client and two team members, create a request,
assign it, move it from New to In Progress to Resolved, and confirm each change
appears in the timeline and dashboard counts.

**Acceptance Scenarios**:

1. **Given** a team member and an active client, **When** the member submits a valid request, **Then** the request receives a stable public identifier, starts in New, and appears in the open queue.
2. **Given** an open request, **When** an authorized member assigns it and changes its priority or due date, **Then** the current values and an audit entry are persisted together.
3. **Given** an overdue unresolved request, **When** a member opens the dashboard, **Then** it is visibly marked overdue and included in the overdue total.
4. **Given** an invalid state transition, **When** a member submits it, **Then** no state changes and a useful validation message is shown.

---

### User Story 2 - Client Self-Service Portal (Priority: P1)

An authenticated client contact creates a service request and follows its
client-visible status, due date, and discussion without seeing internal notes or
another client's data.

**Why this priority**: A long-term collaboration product must reduce status
chasing while maintaining strict confidentiality between clients.

**Independent Test**: Log in as Client A, submit and comment on a request, then
attempt direct URLs for Client B and an internal note; every unauthorized read or
write is denied without leaking whether the record exists.

**Acceptance Scenarios**:

1. **Given** an active client contact, **When** they submit a title, description, and priority, **Then** the request is linked to their client account and visible in their portal.
2. **Given** a client-owned request, **When** the client adds a comment, **Then** the team and that client's contacts can read it in chronological order.
3. **Given** an internal-only note, **When** any client views the request, **Then** the note and its existence are not exposed.
4. **Given** a request owned by another client, **When** a client requests its URL or submits a mutation, **Then** access is denied and no record details are returned.

---

### User Story 3 - Track Retainer Budget Usage (Priority: P2)

An administrator defines a monthly service allowance for a client. Team members
log time against requests, and both team and client see the approved consumed and
remaining allowance for the current period.

**Why this priority**: Fixed monthly consulting budgets require transparent usage
without turning the first release into an invoicing system.

**Independent Test**: Create a 20-hour monthly allowance, log two approved time
entries, and verify that remaining time, utilization percentage, and over-budget
state are calculated consistently on request, client, and dashboard views.

**Acceptance Scenarios**:

1. **Given** an active client, **When** an administrator creates a non-overlapping monthly allowance, **Then** the period and included minutes are stored.
2. **Given** a request and current allowance, **When** a team member logs positive whole minutes, **Then** usage updates without modifying historical entries.
3. **Given** usage beyond the included allowance, **When** the summary is viewed, **Then** remaining time is zero and overage is shown explicitly rather than as a negative balance.
4. **Given** a client user, **When** they view allowance usage, **Then** only approved entries and client-visible descriptions contribute to the displayed breakdown.

---

### User Story 4 - Integrate an External Intake Channel (Priority: P2)

An administrator issues a client-scoped integration credential. An external
system can create and retrieve that client's requests through a versioned JSON
interface, while invalid or overused credentials receive consistent errors.

**Why this priority**: The role explicitly requires data integration; a small,
real contract demonstrates it without adding speculative third-party services.

**Independent Test**: Use a credential for Client A to create and retrieve a
request, verify validation and rate-limit responses, and prove it cannot retrieve
a Client B request.

**Acceptance Scenarios**:

1. **Given** an active integration credential, **When** a valid JSON request is submitted, **Then** a client-scoped request is created once and returned with a stable identifier and location.
2. **Given** an idempotency key already used with the same payload, **When** it is replayed, **Then** the original response is returned without a duplicate request.
3. **Given** an invalid, revoked, or cross-client credential, **When** an API endpoint is called, **Then** a generic authorization error is returned without tenant details.
4. **Given** malformed input or an exceeded request limit, **When** the API is called, **Then** a documented error code and retry guidance are returned.

---

### User Story 5 - Administer Clients and Review History (Priority: P3)

An administrator creates and archives client accounts, invites local users, and
reviews a filterable audit history for operational support and debugging.

**Why this priority**: Administration and traceability make the MVP maintainable,
but the seeded demo can prove the core workflows before full administration.

**Independent Test**: Create a client and users, perform several changes, filter
the audit log by client and event type, archive the client, and confirm new work
is blocked while historical records remain readable to administrators.

**Acceptance Scenarios**:

1. **Given** an administrator, **When** valid client and user details are submitted, **Then** unique active records are created and credentials are never displayed after creation.
2. **Given** normal operations, **When** the administrator filters history, **Then** matching actor, client, action, and timestamp entries are shown newest first.
3. **Given** an archived client, **When** a user attempts to create or update work for it, **Then** the action is rejected while existing history remains intact.

### Edge Cases

- Concurrent updates to one request must not silently overwrite a newer change;
  the second editor is told to refresh and review the current state.
- Due dates and monthly periods use Asia/Taipei for display and UTC for stored
  instants; month boundaries remain deterministic.
- A request may exist before a current retainer period; time logging is rejected
  with a clear message until an applicable period exists.
- An assignee who is deactivated remains visible in history but cannot receive
  new assignments.
- Duplicate client emails and client slugs are rejected case-insensitively.
- Empty search results, long descriptions, zero usage, exact-budget usage, and
  over-budget usage render without broken layouts or ambiguous totals.
- API idempotency keys expire after 24 hours and are scoped to one credential.
- Archived clients, revoked API credentials, and deactivated users cannot create
  new work even if an old session or token remains available.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST authenticate local users and support Administrator,
  Team Member, and Client roles with server-side authorization on every record.
- **FR-002**: The system MUST prevent client users and client-scoped integrations
  from discovering, reading, or mutating another client's records.
- **FR-003**: Administrators MUST be able to create, edit, and archive clients and
  users; archived or deactivated records MUST retain their historical links.
- **FR-004**: Authorized users MUST be able to create, list, filter, view, edit,
  assign, and transition service requests appropriate to their role.
- **FR-005**: Request workflow MUST support New, In Progress, Waiting on Client,
  Resolved, and Closed with explicit allowed transitions and reopening rules.
- **FR-006**: Requests MUST have a stable non-sequential public identifier, title,
  description, client, requester, priority, status, due date, timestamps, and an
  optional active team assignee.
- **FR-007**: Team members and clients MUST be able to add comments; team members
  may mark a comment internal, and internal comments MUST never reach client views
  or API responses.
- **FR-008**: The system MUST record immutable audit events for authentication,
  client/user lifecycle, request creation and field/state changes, comments,
  time entries, allowance changes, and integration credential lifecycle.
- **FR-009**: Administrators MUST define non-overlapping client allowance periods
  with included whole minutes and a calendar start and end date.
- **FR-010**: Team members MUST be able to add immutable positive whole-minute time
  entries to a request and mark whether their description is client-visible.
- **FR-011**: Allowance summaries MUST derive approved usage, remaining included
  time, utilization, and overage from immutable time entries.
- **FR-012**: The team dashboard MUST show open, overdue, due-soon, unassigned, and
  over-budget counts plus a prioritized request queue; the client dashboard MUST
  show only that client's requests and current allowance.
- **FR-013**: List views MUST support pagination and relevant server-side filters;
  all core browser workflows MUST remain usable without JavaScript.
- **FR-014**: State-changing browser forms MUST validate input, protect against
  cross-site request forgery, and redisplay safe errors without losing input.
- **FR-015**: Administrators MUST be able to issue and revoke client-scoped API
  credentials; only a one-time plaintext token is shown and only its hash is stored.
- **FR-016**: A versioned JSON API MUST create and retrieve client-scoped requests,
  support 24-hour idempotency keys for creation, and return a consistent error shape.
- **FR-017**: Authentication and API intake MUST be rate limited, and sensitive
  values or private request bodies MUST not be written to logs or audit metadata.
- **FR-018**: A health endpoint MUST distinguish process availability from database
  readiness for deployment checks.
- **FR-019**: The repository MUST include deterministic demo data, database
  migrations, environment documentation, and a reproducible production-like run.
- **FR-020**: The system MUST display dates and times in Asia/Taipei while storing
  instants consistently, and MUST expose accessible validation and status cues.

### Key Entities *(include if feature involves data)*

- **User**: A local authenticated person with role, active state, and optional
  membership in one client account.
- **Client**: A customer organization with a unique name and slug, active/archive
  state, contacts, requests, allowance periods, and integration credentials.
- **Service Request**: A client-owned unit of work with public identifier,
  requester, assignment, priority, workflow state, due date, and version.
- **Comment**: An immutable chronological message on a request with actor and
  client-visibility flag.
- **Allowance Period**: A date-bounded included service budget for one client.
- **Time Entry**: Immutable minutes logged by a team member against a request and
  applicable allowance period, with approval and visibility state.
- **Audit Event**: Immutable actor/action/subject/timestamp metadata used to explain
  business changes without storing secrets or private message bodies.
- **API Credential**: A revocable client-scoped credential with prefix, secret
  hash, last-used time, and rate-limit identity.
- **Idempotency Record**: A credential-scoped key, request fingerprint, cached
  response reference, and expiry used to prevent duplicate API creation.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A new team member can identify the highest-priority overdue request,
  assign it, update its state, and leave a note in under two minutes using demo data.
- **SC-002**: Automated negative tests cover every client-owned entity and show
  zero cross-client reads or mutations through browser and JSON routes.
- **SC-003**: Replaying the same valid API creation request 20 times with one
  idempotency key results in exactly one stored service request and one identifier.
- **SC-004**: Allowance totals remain exact for zero, exact-limit, and over-limit
  cases, with the same approved usage shown on all authorized views.
- **SC-005**: All primary workflows pass at 320px and desktop widths with keyboard
  navigation, labelled controls, visible focus, and no critical accessibility
  violations in an automated page scan.
- **SC-006**: A clean checkout reaches a ready health state, loads demo data, and
  completes the documented browser and API smoke scenarios in at most 15 minutes.
- **SC-007**: The automated release gate passes unit/integration tests, template and
  configuration lint, schema validation, dependency audit, and production asset build.
- **SC-008**: With 10,000 seeded requests, the first filtered queue page and dashboard
  each respond within 750 ms at the server under a documented local benchmark.

## Assumptions

- The first release serves one consulting team and many client accounts; it is not
  a marketplace and does not require self-service public registration.
- Administrators provision users and share temporary demo credentials out of band;
  password reset and email delivery are intentionally outside v1.
- One request has one current assignee; team collaboration occurs through comments
  and audit history rather than real-time presence.
- Retainer tracking uses minutes and transparent overage only; currency, invoices,
  taxes, payment collection, and payroll are outside v1.
- Time entries are approved immediately in the demo. The data model preserves an
  approval state so a later approval workflow does not require rewriting history.
- Attachments, notifications, webhooks, full-text search, localization beyond
  Traditional Chinese UI copy, and custom workflow builders are outside v1.
- Demo and automated tests use synthetic data only.
