# AgentForge Week 1 Architecture

## One-Page Summary

TODO: Write a roughly 500-word summary after the audit confirms the integration points. The planned architecture is an OpenAI-first Clinical Co-Pilot embedded in OpenEMR, focused on a primary care pre-visit briefing workflow. The agent will use server-side tools to retrieve authorized patient data, produce source-grounded answers, pass responses through a verification layer, and expose citations and uncertainty in the UI.

## Goals

- Embed a Clinical Co-Pilot inside OpenEMR.
- Support a primary care physician's 90-second pre-visit briefing workflow.
- Use OpenAI as the primary model provider.
- Keep patient data access server-side and authorization-aware.
- Verify claims against retrieved OpenEMR records before display.
- Log enough execution detail to debug latency, tool failures, cost, and verification failures.

## Non-Goals

- Do not build a generic medical chatbot.
- Do not bypass OpenEMR authorization.
- Do not use real PHI for this project.
- Do not make unsupported clinical recommendations.
- Do not optimize for broad specialty coverage in the first slice.

## Deployment Direction

Local development will use OpenEMR's Docker Compose setup. The repo includes:

- `docker/development-easy/docker-compose.yml`
- `docker/production/docker-compose.yml`

Public deployment should target Render using Docker or a Render Blueprint where practical. The production Compose file is the starting point, but Render compatibility must be verified because multi-service Compose behavior, persistent volumes, and database hosting may require adaptation.

## Proposed System Flow

1. User logs into OpenEMR.
2. User opens a patient chart.
3. Co-Pilot UI reads current patient context from OpenEMR page/server context.
4. Backend validates the OpenEMR session and user permissions.
5. Backend runs patient data tools for demographics, encounters, medications, labs, problems, and appointments.
6. Backend sends only needed, scoped context to OpenAI.
7. Model produces structured draft output with claim/source mappings.
8. Verification layer checks claims against tool results.
9. UI displays verified answer, source chips, uncertainty, and safe follow-up prompts.
10. Observability captures request trace, tool timings, failures, token use, and verification outcome.

## Trust Boundaries

- Browser to OpenEMR server: user session boundary.
- OpenEMR server to Co-Pilot backend: authenticated application boundary.
- Co-Pilot backend to OpenEMR data tools: authorization and patient-scope boundary.
- Co-Pilot backend to OpenAI: PHI handling boundary under project demo-data and BAA assumptions.
- Model output to user: verification boundary.

## Agent Tools

Initial server-side tools:

- `get_current_patient_context`
- `get_recent_encounters`
- `get_active_problem_list`
- `get_medications`
- `get_recent_labs`
- `get_upcoming_or_current_visit_reason`
- `get_source_record`

Each tool should return structured data with record IDs, timestamps, source type, and display-safe excerpts.

## Verification Strategy

Every patient-specific claim must be linked to one or more retrieved source records. Unsupported claims should be removed, reframed as uncertainty, or blocked before reaching the user.

Verification checks:

- Source attribution exists for factual claims.
- Patient ID in sources matches current chart context.
- User has access to the patient context.
- Tool failures are visible in the response.
- Known unsafe categories are refused or qualified.

## Observability

Log per request:

- User/session identifier or safe surrogate.
- Patient identifier or safe demo identifier.
- Tool calls, durations, and failures.
- Model name, token use, and estimated cost.
- Verification pass/fail result.
- Final response status.

Avoid logging raw PHI in production-oriented logs. For this project, use demo data only.

## Evaluation Plan

Initial eval cases should cover:

- Correct pre-visit summary with source citations.
- Medication change question.
- Abnormal lab follow-up.
- Missing data response.
- Conflicting data response.
- Unauthorized patient access attempt.
- Prompt injection attempt asking to ignore access rules.
- Tool failure fallback.

## Cost Analysis Plan

Estimate actual development spend and projected production costs for:

- 100 users
- 1,000 users
- 10,000 users
- 100,000 users

Cost model should include model tokens, tool/database load, caching, observability storage, retries, and likely architecture changes at each scale.

## Open-Source Model Consideration

OpenAI is the primary implementation target. The architecture should avoid hard-coding model-specific assumptions where practical so that a future open-source or local model can be evaluated for lower cost, data residency, or offline deployment. This is a consideration, not a Week 1 requirement.

## Open Questions

- Which OpenEMR integration point is safest for the first Co-Pilot panel?
- Which existing OpenEMR service/API path should provide patient data tools?
- What Render service topology will work best for OpenEMR plus MariaDB?
- What exact demo patient data should anchor the video?

