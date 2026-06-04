# AgentForge Week 1 Submission

## Status

Ready for context-only demo. Live OpenAI mode is implemented and documented, but this local environment does not currently have `OPENAI_API_KEY` set, so the verified state is `not_configured`.

## GitHub

Repository: https://github.com/jayceparabellum/openemr/

Latest verified local commit:

```text
a1d25ec51 deploy: fix Render blueprint port configuration
```

## What Was Built

The OpenEMR patient dashboard now includes a read-only Clinical Co-Pilot panel for a primary care pre-visit workflow. It loads authenticated patient context through a CSRF-protected endpoint, applies OpenEMR ACL checks, returns source-indexed chart data, and can generate an OpenAI-backed structured summary when a server-side key is configured.

## Safety Story

- OpenEMR remains the system of record.
- The LLM never connects directly to the database.
- The OpenAI key is server-side only.
- The endpoint checks session auth, CSRF, patient demographics ACL, squad ACL, and per-domain ACLs.
- Every generated item must cite source refs from the endpoint's source index.
- Unsupported generated items are dropped before the browser receives them.
- Without `OPENAI_API_KEY`, the system degrades to context-only mode.

## Final Rehearsal

Last local rehearsal:

```text
Mode: context-only
OpenAI status: not_configured
Eval result: pass
Browser result: pass
Panel status: Context ready
Demo patient: pid 1
Lab count shown locally: 1
No OpenEMR error banner: yes
```

Commands used:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\agentforge-copilot-eval.ps1
```

Browser verification used the dev Compose Selenium service and confirmed the Clinical Co-Pilot panel loads on the patient dashboard.

## Submission Blurb

This Week 1 slice embeds a Clinical Co-Pilot into OpenEMR for a primary care physician preparing for a patient visit. The demo focuses on trust before breadth: authenticated chart context, ACL-filtered data access, source-indexed responses, safe missing-data handling, eval coverage, and an OpenAI provider boundary that can be enabled by setting `OPENAI_API_KEY`.

## Demo Links

- [Demo runbook](DEMO_RUNBOOK.md)
- [Architecture defense](ARCHITECTURE_DEFENSE.md)
- [Eval plan](EVALS.md)
- [Deployment notes](DEPLOYMENT.md)
- [AI cost analysis](AI_COST_ANALYSIS.md)
- [Social post draft](SOCIAL_POST.md)
