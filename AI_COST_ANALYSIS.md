# AgentForge Week 1 AI Cost Analysis

## Summary

Week 1 has two operating modes:

1. `context-only`: verified locally when `OPENAI_API_KEY` is not configured. This mode has no LLM API cost.
2. `ai-summary`: enabled when `OPENAI_API_KEY` is configured. The server sends bounded, source-indexed patient context to OpenAI and validates the structured response before rendering.

The verified local development spend for OpenAI API calls is `$0.00` because this environment did not have `OPENAI_API_KEY` configured. The estimates below project the configured default model path, `gpt-4o-mini`, for production use.

Pricing source checked June 2026: [OpenAI API pricing](https://openai.com/api/pricing/) lists `gpt-4o-mini` at `$0.15 / 1M input tokens` and `$0.60 / 1M output tokens`; the Responses API is billed at the selected model's token rates.

## Usage Assumptions

The Week 1 user is a primary care physician using the Co-Pilot for pre-visit review.

| Assumption | Value | Rationale |
|---|---:|---|
| Patient briefs per user per clinic day | 20 | Matches a busy primary care schedule. |
| Clinic days per month | 22 | Approximate business days. |
| Requests per user per month | 440 | `20 * 22`. |
| Input tokens per request | 4,500 | Bounded chart context, source index, and system/developer instructions. |
| Output tokens per request | 700 | Structured summary, risks, missing data, and follow-up prompts. |
| Retry / validation overhead | 10% | Accounts for schema repair, transient failures, or regenerated responses. |

Estimated model cost per successful request:

```text
Input:  4,500 * $0.15 / 1,000,000 = $0.000675
Output:   700 * $0.60 / 1,000,000 = $0.000420
Base request cost:                       $0.001095
With 10% retry overhead:                 $0.001205
```

## Monthly Projection

| Monthly active users | Requests / month | OpenAI model cost / month | Architecture notes |
|---:|---:|---:|---|
| 100 | 44,000 | `$53` | Single OpenEMR deployment can use synchronous generation with basic request logging and conservative rate limits. |
| 1,000 | 440,000 | `$530` | Add response caching for unchanged charts, per-user quotas, queue-backed retries, and structured cost dashboards. |
| 10,000 | 4,400,000 | `$5,300` | Move generation to a worker pool, precompute briefs for scheduled visits, shard audit logs, and introduce tenant-level budgets. |
| 100,000 | 44,000,000 | `$52,998` | Requires multi-region/tenant-aware deployment, strict PHI data boundaries, async prefetching, model fallback strategy, and formal FinOps controls. |

These numbers are model-token estimates only. They do not include hosting, database, log storage, observability SaaS, backups, on-call, security review, compliance work, or support.

## Non-Token Costs To Budget

| Cost area | 100 users | 1K users | 10K users | 100K users |
|---|---|---|---|---|
| App hosting | Small web + DB service | Dedicated DB and app service | Worker pool and queue | Multi-region, tenant-aware platform |
| Observability | Local/OpenEMR logs | Centralized logs | Log retention controls | Dedicated audit pipeline |
| Storage | Demo-sized | Per-tenant backups | Audit/document retention planning | Formal lifecycle and deletion policy |
| Security/compliance | Basic secret management | BAA/vendor review | Tenant isolation and incident playbooks | Enterprise compliance program |
| Reliability | Manual redeploy | Health checks and rollback | SLOs and runbooks | Capacity planning and on-call rotation |

## Cost Controls

- Keep chart context bounded and source-indexed rather than sending whole patient records.
- Use non-streaming structured output for the final clinical answer so failed validation does not reach the browser.
- Cache context-only results when the chart has not changed.
- Precompute briefs for scheduled visits at larger scale.
- Use smaller models for routing/classification and reserve larger models for rare, high-complexity summaries.
- Track model, token count, latency, validation result, retry count, and displayed/not-displayed status per request.
- Do not log raw PHI prompts or responses in production-oriented logs.

## Scale-Driven Architecture Changes

At 100 users, the Week 1 architecture can remain mostly synchronous because the primary requirement is a trustworthy demo.

At 1K users, add queue-backed retry handling, tenant budgets, and cost alerts. The model boundary should remain provider-neutral enough to compare OpenAI-compatible alternatives.

At 10K users, move generation out of the web request path. The dashboard should show cached/precomputed briefs first, then refresh asynchronously when patient data changes.

At 100K users, the system needs formal multi-tenant isolation, audit log lifecycle management, regional deployment planning, and model-routing policy. Token cost is material, but operational safety and compliance dominate the architecture.
