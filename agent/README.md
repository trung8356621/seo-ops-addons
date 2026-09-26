# LEGACY / REFERENCE-ONLY

This Agent implementation is **not** the canonical runtime architecture.
It is retained as behavioral/reference material for the future Agent rewrite.

## Status (isolation cutover)

| Surface | Status |
|---------|--------|
| Agent Workspace (chat, packs, planning, skills UI) | **Disabled** — not registered by default |
| Peer addon discovery (`agent` slug) | **Skipped** via `ADDON_SKIP_SLUGS` |
| Filament Agent pages under SEO panel | **Not discovered** |
| Agent Workspace schedules / observability jobs | **Not scheduled** |

## Transitional code still living under this folder

`Automation/` (Business Hook engine) and `Extension/` (extension SDK) remain on disk under `Omnichannel\Addons\Agent\*` because historical cutover placed shared platform plumbing here.

They are **not** the future Agent product. They may still be bootstrapped by `seo-content-ai-compat` until extracted to owning packages. Do not treat them as the new Agent architecture.

## Rules

- Do **not** add new production dependencies on Agent Workspace (`Services\AgentWorkspace\*`, Agent Filament chat, Agent packs/planning).
- Do **not** restore Agent Workspace registration without an explicit rewrite plan.
- Content Project, Content, SEO, and Search must not import Agent Workspace types.
- WordPress Bridge and System Remote remain out of scope for Agent work.

## Future Agent rewrite

The next Agent will consume stable Domain / MCP / Content Project boundaries — not
`ContentProjectAgentGateway` internals or direct domain model writes from Agent actions.
