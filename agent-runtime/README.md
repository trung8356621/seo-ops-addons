# Agent Runtime

New Agent peer addon. This is not a continuation of `addons/agent`.

`addons/agent` stays **LEGACY / REFERENCE-ONLY**. This addon must not import `Omnichannel\Addons\Agent\...` and must not recreate `seo_agent_*` tables.

## Flow

```text
User
→ Routing / Decision Runtime
→ Retrieval Plan
→ SEO Access HTTP executor
→ Retrieval Bundle
→ Answer Runtime
→ AgentResponse
→ React renderer
```

## Project scope

| UI | Scope |
|----|--------|
| All Sites | `{ "type": "global" }` |
| One site | `{ "type": "site", "site_id": 7, "site_ref": "site:7" }` |

Global is not `site_id = 0` and not a null site. Global retrieval is unsupported until a separate API contract exists.

## Decision models

Jev and Laya are registered in AI Settings as the **Decision Models** group (`ai-prompt`). Agent Runtime only asks `DecisionModelSource` / `DecisionModelGateway`.

## SEO Access

Site turns call the existing HTTP contract:

1. `POST /api/v1/services/seo/access` with server-only bearer
2. `GET /api/v1/access/{token}/site|keywords|gsc`

The permanent bearer comes from `AGENT_RUNTIME_SEO_ACCESS_BEARER`. It is not stored in `service_api_credentials` (hash only) and must never appear in model input.

Canonical doc: `omnichannel-client/docs/modules/AGENT_RUNTIME.md`.
