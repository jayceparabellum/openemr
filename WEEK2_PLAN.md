# AgentForge Week 2 Plan

## Sprint Goal

Extend the Week 1 Clinical Co-Pilot from a source-grounded OpenEMR chart summarizer into a narrow multimodal evidence agent, while also starting the surprise requirement: a modern-framework patient dashboard that consumes OpenEMR REST/FHIR APIs.

The Week 2 demo story should remain one clinician workflow:

1. A primary care physician opens a patient dashboard.
2. The system can ingest a lab PDF and intake form tied to that patient.
3. Extracted facts are structured, validated, and source-cited.
4. The Co-Pilot retrieves small guideline evidence relevant to the patient's context.
5. A supervisor routes work to explicit workers and logs handoffs.
6. Eval CI blocks regressions in extraction, citation, evidence, safety, and PHI logging.

## Tracks

### Track A: Multimodal Evidence Agent

Core deliverables:

- `attach_and_extract(patient_id, file_path, doc_type)` or equivalent.
- `lab_pdf` extraction schema.
- `intake_form` extraction schema.
- Source document storage in OpenEMR.
- Derived facts linked back to source citations.
- Small guideline corpus for the primary-care workflow.
- Hybrid retrieval: keyword plus vector search.
- Rerank step with Cohere Rerank or equivalent.
- Supervisor plus two workers:
  - `intake-extractor`
  - `evidence-retriever`
- 50-case golden eval set with boolean rubrics.
- PR-blocking eval gate.
- Observability for tool sequence, latency, token/cost estimate, retrieval hits, extraction confidence, and eval outcome.

### Track B: Modern Patient Dashboard

Core deliverables:

- Modern dashboard implementation in a framework selected for the sprint.
- OAuth2/OIDC login path against OpenEMR.
- Patient header: name, DOB, sex, MRN, active status.
- FHIR-backed cards:
  - Allergies
  - Problem List
  - Medications
  - Prescriptions
  - Care Team
- One additional section: lab results, because it reinforces the Week 2 lab PDF story.
- `PATIENT_DASHBOARD_MIGRATION.md` explaining the framework choice and tradeoffs.

## Recommended Scope

Keep both tracks narrow. The best Week 2 slice is not a general medical-document platform and not a full OpenEMR frontend rewrite. It is a proof that the Week 1 safety architecture can absorb messy documents, evidence retrieval, and a modern dashboard surface without losing grounding.

## Milestones

### Milestone 1: Architecture Defense

- Complete `W2_ARCHITECTURE.md`.
- Complete `PATIENT_DASHBOARD_MIGRATION.md` initial defense.
- Define schemas and eval rubric before implementation.
- Document security risks, especially PHI in prompts, traces, screenshots, and local notes.

### Milestone 2: Local MVP

- Ingest a sample lab PDF and an intake form.
- Extract strict JSON with citations.
- Retrieve first guideline evidence snippet.
- Show extraction/evidence state in the existing Week 1 Co-Pilot area or a small Week 2 panel.

### Milestone 3: Early Submission

- Add supervisor plus two workers with logged handoffs.
- Add 50 synthetic/demo eval cases.
- Add CI or local blocking script that fails on regression.
- Deploy or document the deploy path.
- Record 3-5 minute walkthrough.

### Milestone 4: Final

- Harden missing-data behavior and refusals.
- Add cost/latency report.
- Verify deployed app.
- Run proof screenshot flow for dashboard and Co-Pilot UI.
- Prepare interview defense.

## Immediate Next Tasks

1. Build strict schema files for `lab_pdf` and `intake_form`.
2. Inspect OpenEMR document storage and FHIR Observation paths for where source docs and extracted labs should live.
3. Create a tiny guideline corpus for primary care: diabetes/A1C, hypertension/BP, medication reconciliation, allergy safety.
4. Decide modern dashboard framework and directory layout.
5. Add the first 10 eval cases, then expand to 50 once the schema shape is stable.

## Security Reminder

The local Week 2 notes include API-key shaped secrets. Do not commit them. Rotate any key that was stored in plaintext notes before using it in a deployed environment. Use `.env` locally and Render/GitHub secrets for deployment or CI.
