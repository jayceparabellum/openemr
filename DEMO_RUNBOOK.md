# AgentForge Demo Runbook

## Goal

Show a primary care physician workflow inside OpenEMR: open a patient chart, load the Clinical Co-Pilot panel, prove it is using authenticated chart context, and demonstrate the safety gates with evals.

## Local Startup

```powershell
cd C:\Users\jayce\OneDrive\Documents\OpenEMR\docker\development-easy
docker compose up -d
docker compose exec -T openemr /root/devtools dev-reset-install-demodata
docker compose restart couchdb
```

Open `http://localhost:8300` and log in with `admin / pass`.

## Optional OpenAI Live Mode

Set the key in the shell that starts Docker, then recreate the OpenEMR service:

```powershell
$env:OPENAI_API_KEY = "sk-..."
$env:AGENTFORGE_OPENAI_MODEL = "gpt-4o-mini"
cd C:\Users\jayce\OneDrive\Documents\OpenEMR\docker\development-easy
docker compose up -d --force-recreate openemr
```

Without `OPENAI_API_KEY`, the demo intentionally runs in context-only mode. The panel still proves auth, ACL checks, source indexing, missing-source handling, and eval coverage.

## Evals

Run the endpoint evals from the repo root:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\agentforge-copilot-eval.ps1
```

Seed one demo-only A1C lab and run the positive lab path:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\agentforge-copilot-eval.ps1 -SeedLab
```

Expected result: all rows pass. If OpenAI is not configured, `OPENAI-LIVE` should still pass with `not_configured`.

If `-SeedLab` has already been run in the local database, the normal eval command may report existing local lab data instead of a missing-labs state. That is acceptable for rehearsal; the runner verifies that the lab state is explicit either way.

## Demo Path

1. Open `http://localhost:8300`.
2. Log in as `admin / pass`.
3. Open a demo patient dashboard, for example `http://localhost:8300/interface/patient_file/summary/demographics.php?set_pid=1`.
4. Confirm the Clinical Co-Pilot panel shows `Context ready`.
5. Point out available context counts and missing/limited source warnings.
6. If OpenAI is configured, confirm `Summary ready` and source badges appear under every generated item.

Current verified local fallback behavior without `OPENAI_API_KEY`: the panel shows `Context ready` and the message `Context loaded. AI summary generation is pending provider configuration.`

## What To Say

- OpenEMR remains the system of record.
- The browser never receives the OpenAI key.
- The endpoint checks OpenEMR session auth, CSRF, patient ACL, squad ACL, and per-domain ACLs before building context.
- Model output is not trusted automatically: every generated item must cite a source ref from the endpoint's source index.
- Without a key, the system degrades safely to context-only mode.
