# LEGACY / REFERENCE-ONLY

This Agent implementation is **not** the canonical runtime architecture.
It is retained as behavioral/reference material for the future Agent rewrite.

```text
LEGACY / REFERENCE-ONLY
NOT active runtime
NOT canonical architecture
```

## Status (retirement CLOSED)

| Surface | Status |
|---------|--------|
| Agent Workspace (chat, packs, planning, skills UI) | **Retired** — not discovered / not registered |
| Peer addon discovery (`agent` slug) | **Skipped** via `ADDON_SKIP_SLUGS` (`wp-headless,agent`) |
| Filament Agent pages under SEO panel | **Not discovered** |
| Agent Workspace schedules / observability jobs | **Not scheduled** |
| Agent Workspace DB (`seo_agent_*`) | **Dropped** (migration `2026_09_26_100000_retire_legacy_agent_workspace_seo_agent_tables`) |
| Agent Workspace product automations (`seo_agent_automations*`) | **OFF / retired** |

## NOT the same as Content Project MCP

These remain **ACTIVE** and Content Project-owned — do not confuse with `seo_agent_*`:

- `seo_content_project_agent_sessions`
- `seo_content_project_agent_plans`
- `seo_content_project_agent_plan_steps`
- `seo_content_project_agent_approvals`
- HTTP `/api/v1/agent/mcp/*` (legacy compatibility until a separate MCP refactor)

## Transitional shared infrastructure still living under this folder

`Automation/` (Business Hook engine) and `Extension/` (extension SDK) remain on disk under `Omnichannel\Addons\Agent\*` because historical cutover placed shared platform plumbing here.

They are **not** the retired Agent Workspace product and are **not** the future Agent.

> `Omnichannel\Addons\Agent\Extension\*` is transitional shared infrastructure and is not part of the retired Agent Workspace product. Namespace extraction is a separate future task.

Business Hook `Automation/*` similarly remains bootstrapped by `seo-content-ai-compat` for current Content Project / Publishing / WordPress lifecycle events until extracted.

## Rules

- Do **not** add new production dependencies on Agent Workspace (`Services\AgentWorkspace\*`, Agent Filament chat, Agent packs/planning, `Models\AgentWorkspace\*`, `seo_agent_*`).
- Do **not** restore Agent Workspace registration or recreate `seo_agent_*` tables for product use.
- Content Project, Content, SEO, and Search must not import Agent Workspace types.
- WordPress Bridge and System Remote remain out of scope for Agent work.

## Future Agent rewrite

The next Agent will consume stable Domain / MCP / Content Project boundaries — not
legacy Workspace tables or direct domain model writes from retired Agent actions.
Those APIs are **not** implemented yet.
