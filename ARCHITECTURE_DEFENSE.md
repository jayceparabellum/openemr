# AgentForge Architecture Defense

## Position

This is a conservative clinical co-pilot, not an autonomous medical agent. OpenEMR remains the system of record and the authority for identity, patient selection, permissions, and audit behavior. The LLM is a bounded drafting service behind server-side controls.

## Defense Points

1. The LLM never talks directly to the database.
2. The OpenAI key stays server-side.
3. The browser can request only the active patient context through a CSRF-protected OpenEMR endpoint.
4. The endpoint checks patient demographics ACL, squad ACL, and per-domain ACLs before returning data.
5. Every context object includes source metadata.
6. Every generated summary, risk, and follow-up prompt must cite valid source refs.
7. Unsupported generated items are dropped before reaching the UI.
8. Missing labs or restricted domains are shown as uncertainty, not hallucinated facts.
9. The system degrades to context-only mode when OpenAI is not configured.
10. Evals cover happy path, missing labs, invalid patient, prompt injection, unsupported claims, and source integrity.

## Team Pitch

We are not asking the team to trust a chatbot with clinical authority. We are adding a read-only pre-visit briefing panel that respects OpenEMR's existing security model and treats model output as untrusted until it passes source validation. The Week 1 slice proves the riskiest pieces first: authenticated patient context, source indexing, safe fallback, and eval coverage. That gives us a credible demo without pretending the model is a clinician.

## Known Limits

- Live OpenAI generation requires `OPENAI_API_KEY` in the OpenEMR container environment.
- Demo data is synthetic and sparse.
- The first UI shows source labels, not full source drill-down.
- Production deployment must remove dev-only services and default credentials.
