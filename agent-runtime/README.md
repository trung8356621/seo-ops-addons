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

Decision Models is the AI Settings area. Jev is discovered from supported provider catalogs. OpenRouter's default model list is text-only; Decision sync also reads `output_modalities=decisions` (`typesafe/jev-latest`, `typesafe/jev-1.13`). `typesafe/jev-router` is text output and is not a Decision model. Laya is not currently callable or discoverable through existing AI Connections. Laya support requires a dedicated Jev-compatible/self-host Decisions connection transport.

Agent Runtime only asks `DecisionModelSource` / `DecisionModelGateway`.

## SEO Access

Site turns call the existing HTTP contract:

1. `POST /api/v1/services/seo/access` with server-only bearer
2. `GET /api/v1/access/{token}/site|keywords|gsc`

The permanent bearer comes from `AGENT_RUNTIME_SEO_ACCESS_BEARER`. It is not stored in `service_api_credentials` (hash only) and must never appear in model input.

Canonical doc: `omnichannel-client/docs/modules/AGENT_RUNTIME.md`.
