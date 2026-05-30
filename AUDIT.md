# AgentForge Week 1 Audit

## One-Page Summary

OpenEMR is running locally through the documented easy development Docker Compose environment, and the first audit pass confirms that the Clinical Co-Pilot should be built as a conservative, server-side extension rather than a browser-only widget or direct database client. The local stack exposes OpenEMR on ports 8300 and 9300, plus development-only services including phpMyAdmin, MariaDB, CouchDB, Mailpit, Selenium, and VNC. That is useful for the sprint, but it creates an important deployment constraint: the Render/public environment must not expose development services, default credentials, debug tooling, database ports, mail capture, or VNC.

The initial environment also surfaced a setup gate. Before running `dev-reset-install-demodata`, the OpenEMR health endpoint returned `setup_required`, with database, filesystem, session, and cache checks passing but installation and OAuth key checks failing. After installing standard demo data, the login page was reachable and the database contained demo users, patients, encounters, issues, and audit rows. This matters because the agent cannot be evaluated against the chart workflow until the demo data install is part of the setup instructions.

Authorization is a central architectural constraint. OpenEMR uses ACL checks through `OpenEMR\Common\Acl\AclMain::aclCheckCore()`, and REST routes call `RestConfig::request_authorization_check()` for patient, encounter, medication, allergy, and medical-problem APIs. The Co-Pilot must reuse those controls rather than introduce a parallel access model. The server-side agent tools should require an authenticated OpenEMR session, verify the current user's ACLs for the requested patient data type, and bind every request to the current patient context.

Auditability is already present in the platform and should be extended rather than bypassed. `PatientSessionUtil::setPid()` writes a patient `view` event through `EventAuditLogger`, and `ADODB_mysqli_log` wraps SQL execution to call `auditSQLEvent()` unless explicitly skipped. The local demo database already has rows in `audit_master` and `audit_details`. The Co-Pilot should add its own request trace while avoiding raw PHI in production logs.

The largest data-quality finding is that the installed demo dataset is small: 3 patients, 3 encounters, 9 issue-list rows, 1 medication, and 0 lab results. This is enough for the first pre-visit summary and medication-change demo, but not enough for a truthful lab-focused demo unless we add or import lab data. The first agent slice should therefore lead with source-grounded recent context, problems, encounters, and medications, and treat labs as a missing-data case until richer data exists.

## Audit Scope

This audit supports a Clinical Co-Pilot embedded in OpenEMR for a primary care physician preparing for a patient visit. The first product wedge is a 90-second pre-visit briefing that summarizes relevant patient context with source attribution.

The audit must be completed before building the AI layer.

## Security Audit

### Authentication

- Local login page is reachable at `http://localhost:8300` and returns `OpenEMR Login`.
- The easy development Compose file provisions `OE_USER=admin` and `OE_PASS=pass`.
- Demo data adds multiple active users, including `admin`, `clinician`, `physician`, `receptionist`, `accountant`, and `zhportal`.
- Default credentials are acceptable only for local/demo use. They must not ship in a public Render deployment.

### Authorization

- OpenEMR centralizes ACL checks in `src/Common/Acl/AclMain.php`; `AclMain::aclCheckCore()` starts at line 166.
- Patient context lives in the session through `src/Common/Session/PatientSessionUtil.php`; `getPid()` reads the active patient ID and `setPid()` writes a patient `view` audit event.
- REST routes in `apis/routes/_rest_routes_standard.inc.php` call `RestConfig::request_authorization_check()` for patient and clinical-data endpoints:
  - `GET /api/patient` requires `patients/demo`.
  - `GET /api/patient/:puuid/encounter` requires `encounters/auth_a`.
  - Encounter notes/vitals endpoints require `encounters/notes`.
  - Medication, allergy, and medical-problem endpoints require `patients/med` or related patient ACLs.
- Co-Pilot data tools must call existing ACL checks before fetching patient data. A valid OpenEMR login is not enough; each tool must verify the user's permission for the specific clinical domain.

### Data Exposure

- Development Compose exposes OpenEMR, phpMyAdmin, MariaDB, CouchDB, Mailpit, Selenium, and VNC ports on the host.
- Development settings enable `DEVELOPER_TOOLS`, Xdebug, REST APIs, FHIR APIs, portal APIs, CouchDB, LDAP, and SMTP through Mailpit.
- Local credentials are visible in `docker/development-easy/docker-compose.yml`, including MariaDB root/user passwords, OpenEMR admin password, CouchDB credentials, SMTP credentials, and VNC password.
- These are development-only conveniences. Public deployment must remove phpMyAdmin, Mailpit, Selenium/VNC exposure, direct database ports, Xdebug, and default passwords.
- Co-Pilot logs must not capture raw chart text or model prompts containing PHI in production-oriented logs.

## Performance Audit

- First local boot pulled large Docker images and then spent several minutes preparing the OpenEMR container. On Windows/OneDrive bind mounts, a large ownership pass delayed Apache startup.
- Restart after images/volumes existed reached healthy state faster, but still had a warm-up period before OpenEMR served traffic.
- The local app returns the login page with HTTP 200 once healthy.
- The agent should not do broad database scans at request time. The pre-visit brief should retrieve a bounded set of current patient records: recent encounters, active problems, medication list, visit context, and labs only when present.
- For the 90-second PCP workflow, the Co-Pilot should stream or render partial state: `retrieving meds`, `labs unavailable`, and `verified sources` rather than blocking on every source.

## Architecture Audit

- OpenEMR is a PHP application with classic `interface/` pages, `src/` services, `library/` helpers, REST routes under `apis/`, and database tables such as `patient_data`, `form_encounter`, `lists`, `prescriptions`, and `procedure_result`.
- `src/Services/PatientService.php` is a strong candidate for patient demographic lookup; it defines `TABLE_NAME = patient_data`, implements `search()`, `getOne()`, `findByPid()`, and provider lookup helpers.
- REST routes already exist for patient, encounter, medication, allergy, and medical-problem data, and they include authorization checks. They are a strong candidate for agent tool boundaries if OAuth/session wiring is feasible.
- Direct SQL is possible inside OpenEMR, but agent tools should prefer existing services/controllers where they preserve validation, authorization, and audit behavior.
- Candidate Co-Pilot UI location remains open, but it should be embedded in the patient-chart workflow where `pid` is already in session rather than as a standalone global chatbot.

## Data Quality Audit

- Installed standard demo data contains:
  - 3 rows in `patient_data`.
  - 3 rows in `form_encounter`.
  - 9 rows in `lists`.
  - 1 row in `prescriptions`.
  - 0 rows in `procedure_result`.
  - 9 non-empty usernames in `users`, with a mix of active, inactive, authorized, and unauthorized users.
- This dataset is enough to demonstrate patient lookup, encounter summary, issue-list summary, role differences, and medication retrieval.
- The dataset is not enough to demonstrate recent-lab summarization unless labs are added or imported.
- Missing labs should become a first-class eval case: the agent should say no lab results are available rather than inventing normal or abnormal results.

## Compliance And Regulatory Audit

- Project uses demo data only.
- Assignment assumption: treat LLM providers as covered by BAA/no-training terms for Gauntlet purposes.
- Production gaps before real PHI use:
  - Remove default credentials and dev-only services.
  - Enforce HTTPS externally.
  - Configure secrets through environment/secret manager, not committed Compose defaults.
  - Define log retention and PHI redaction.
  - Ensure LLM request/response logging is either disabled, redacted, or covered by a compliant retention policy.
  - Record Co-Pilot access and generated-response events in a reviewable audit trail.

## Findings

| ID | Area | Severity | Finding | Evidence | Impact | Recommendation |
|----|------|----------|---------|----------|--------|----------------|
| A-001 | Setup | P1 | OpenEMR initially reported `setup_required` before demo installation. | `readyz` returned `installed:false` and `oauth_keys:false`; `dev-reset-install-demodata` was required. | No patient workflow or agent audit is meaningful until the demo install step is documented and repeatable. | Add setup instructions for `docker compose exec -T openemr /root/devtools dev-reset-install-demodata` and CouchDB restart. |
| A-002 | Security | P1 | Development Compose exposes multiple services and default credentials. | Host ports include 8300, 9300, 8310, 8320, 5984, 6984, 8025, 1025, 4444, 7900; credentials are in `docker/development-easy/docker-compose.yml`. | Public deployment could expose PHI, admin tools, mail, DB, debug, or browser automation surfaces. | Render deployment must use production topology, private DB/networking, secrets, and no phpMyAdmin/Mailpit/Selenium/VNC exposure. |
| A-003 | Authorization | P1 | Patient-data access must use OpenEMR ACLs, not model-side filtering. | `AclMain::aclCheckCore()` and REST `request_authorization_check()` guard patient/encounter/medication routes. | An agent that queries data first and filters later risks unauthorized PHI exposure. | Implement server-side tools that check session user, current patient, and domain ACL before every data fetch. |
| A-004 | Auditability | P1 | OpenEMR already audits patient view and SQL activity. | `PatientSessionUtil::setPid()` logs `view`; `ADODB_mysqli_log` calls `auditSQLEvent()`; demo DB has 70 audit rows. | Co-Pilot bypassing these paths would create untraceable clinical data access. | Extend audit trail with Co-Pilot request events and prefer existing services/API paths that preserve audit behavior. |
| A-005 | Data Quality | P2 | Demo data has no lab results. | `procedure_result` row count is 0. | A lab-focused demo would force unsupported claims or look empty. | Make labs a missing-data behavior initially, or import/add lab data before promising lab summaries. |
| A-006 | Performance | P2 | Windows/OneDrive bind mounts slow first-run container setup. | First boot had a long image/setup phase and delayed Apache startup. | Demo reliability and setup instructions may suffer if reviewers reproduce locally. | Document first-run delay and avoid relying on cold-start speed for product demo. |

## Architecture Implications

- Co-Pilot must be server-side and authorization-aware.
- The model must never receive broad, unrestricted patient records.
- Initial tools should target bounded patient context: demographics, recent encounters, active issues, medications, visit context, and labs only when present.
- Every tool result must carry source metadata: table/service, record ID or UUID, timestamp/date, and source label.
- Verification must reject unsupported claims and explicitly surface missing data.
- Observability should record tool calls, latency, failures, token/cost, verification results, and user/patient safe identifiers.
- Public deployment must not mirror the easy development stack's exposed service surface.

