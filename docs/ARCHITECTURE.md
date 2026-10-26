# DENR FP-OMS — Architecture and Schema Overview

## Request flow

```mermaid
flowchart TD
    Browser[Browser • Bootstrap 5] --> Entry[public/index.php • Router]
    Entry --> Security[Auth • Session • CSRF • Server-side RBAC]
    Security --> Domain[Registration / Review / Internal Users]
    Domain --> PDO[PDO Prepared Statements]
    PDO --> DB[(MySQL / MariaDB)]
    Domain --> Private[(Private storage/registrations)]
    Domain --> Audit[(audit_logs)]
```

## Step 1 state machine

```mermaid
flowchart TD
    Register[Applicant registration • valid ID] --> Pending[pending_review]
    Pending -->|LPDD-FUS approves| Active[active]
    Pending -->|LPDD-FUS returns with remarks| Fix[needs_correction]
    Fix -->|Applicant edits / resubmits| Pending
    Active --> Future[Step 2 · Future development]
```

`users.status` is the authoritative account-activation state. Each correction increments `applicant_registrations.revision` and a review record stores the reviewed revision. Historical uploads and decisions are retained for traceability. Registration documents store one row per uploaded file, with a nullable `id_type` added by `007_registration_id_types.sql`. All uploaded files across revisions are shown (rather than only one per category); legacy IDs without a recorded type remain identifiable. A new submission requires at least one Valid ID with a chosen ID type; corrected submissions may add further IDs and supporting documents. A reviewer cannot approve a registration that is no longer pending.

## Core data entities

| Entity | Purpose | Relationship |
|---|---|---|
| `offices` | Regional / PENRO / CENRO hierarchy | users.office_id optional |
| `users` | Account identity, password hash, activation status | 1:N to user_roles |
| `roles` | Known functional roles | 1:N to user_roles |
| `user_roles` | Flexible many-to-many RBAC assignment | PK(user_id, role_id) |
| `applicant_registrations` | One-time applicant company/contact details | 1:1 users |
| `user_designations` | Pending staff identities and reference office contacts; not login accounts | Optional link to one internal user |
| `registration_documents` | Immutable private-upload revisions | N:1 registrations |
| `registration_reviews` | LPDD-FUS approval/correction decisions | N:1 registrations |
| `login_attempts` | Login rate limiting (per email hash/IP) | Independent audit data |
| `audit_logs` | Recorded security/business actions | actor_user_id nullable |

**Scaffold only in `002_future_workflow.sql`:** `permit_applications`, `application_documents`, `workflow_actions`, `permit_records`. Additional workflow entities (RRDC sessions, TOR templates, signatures, assignments, notifications) will be designed when Steps 2–11 are implemented and their detailed business rules approved.

## RICTU User Management

* The Configuration → User Management menu is visible only to RICTU Super Administrators, and each matching router action independently calls `require_role('super_admin')` followed by CSRF checks for POST changes.
* The `008_user_management.sql` migration creates nullable internal-user contact/station fields and imports 34 pending staff designation records based on the supplied October 21, 2026 assignment form. Imported office emails are references only. No user account, login credential, or password is automatically created.
* An authorized administrator may create an account from a designation, link a pre-existing matching internal account, edit its profile/role/status, or reset its password. Unique login email validation, hashing, first-login password change, and audit logs apply.
* Administrator self-demotion/suspension and removal of the last active Super Administrator are blocked. User role changes use a single selected primary internal role, and server-side permissions remain authoritative.
* The `gis_focal` code adds a role for GIS Focal staff but does not grant access to unimplemented permit workflow features.

## Authorization matrix — Phase 1

| Feature | Public | Applicant | LPDD-FUS | RICTU Admin | Other Internal |
|---|:---:|:---:|:---:|:---:|:---:|
| Register new applicant | ✓ | — | — | — | — |
| Sign in and change password | ✓* | ✓ | ✓ | ✓ | ✓ |
| View own registration/status | — | ✓ | — | — | — |
| Edit own registration if correction requested | — | ✓ | — | — | — |
| Review registrations and activate applicant | — | — | ✓ | — | — |
| Download applicant registration docs | — | Own | ✓ | ✓ | — |
| Provision internal users | — | — | — | ✓ | — |
| File permit application (Step 2) | — | Not implemented | — | — | — |

*Sign-in is public; password changes require an authenticated user.

## Design trade-offs / controls

- A dependency-light PHP architecture was chosen for straightforward DENR hosting and PHP 8 familiarity. A framework/ORM and Composer packages can be introduced in later phases.
- Private document storage is physically outside `/public`, with randomized filenames, file-signature checks and explicit read authorization.
- Initial staff roles are provisioned by RICTU. Super Administrator does **not** implicitly override LPDD-FUS business decisions in this release.
- Server sessions are SameSite=Lax, HttpOnly and HTTPS-Secure when behind HTTPS; production deployments must enforce HTTPS and protect session storage.
- Each sensitive POST operation uses CSRF validation. SQL uses prepared statements. Login attempts are throttled based on server-visible IP and email hash (a future scheduled cleanup job should prune old attempts).
- UI uses Bootstrap 5 from a CDN. Before deployment on restricted networks, host a vetted local copy and change CSP to self-hosted-only.
