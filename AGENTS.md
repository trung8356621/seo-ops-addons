# omnichannel-addons

Peer-addon monorepo — **one Git repo**, many peer folders. No parent/child hierarchy.

## Peers
search-foundation · seo · search-intelligence · ai-prompt · content · content-projects · media · wordpress · publishing · site-sync · agent · social · commerce · seeding · seo-content-ai-compat

## Rules
1. Addon cannot add business columns to another addon's table.
2. Cross-addon: capability / command / event / stable DTO only.
3. No sibling implementation imports.
4. `seo-content-ai-compat` = Filament views/lang/panel bootstrap only — no new business.

## Feature → owner
| Feature | Folder |
|---------|--------|
| Article / editor | `content/` |
| Featured / gallery | `media/` |
| SEO score/audit | `seo/` |
| Performance Hub | `search-intelligence/` |
| **Topic Core (site-scoped)** | `search-intelligence/` — see `docs/modules/TOPIC_CORE.md` |
| WP sync | `wordpress/` |
| Publishing | `publishing/` |
| Content Project | `content-projects/` |
| AI/prompt | `ai-prompt/` |
| Site Sync | `site-sync/` |
| Agent/MCP | `agent/` |
| Social Profile / manual share | `social/` |
| Seeding Topic V2 / Link Intelligence | `seeding/` |

## Website type (Manufacturer ≠ production)

UI label **Manufacturer** persists as internal key **`production`**. Ecommerce persists as **`e-commerce`**.  
Canonical doc: `docs/modules/WEBSITE_TYPE.md`. Do not rename `production` casually; do not treat `production` as a UI label.

## Seeding AI vs SEO AI (permanent)

Seeding Gen Comment AI is **intentionally and permanently independent** from the SEO AI Prompt/Task/History pipeline.

- Seeding owns its prompt setting, MCP context, generation flow, debug logs, and retention.
- Do **not** extract/reuse/refactor SEO Prompt/Task/History for Seeding, or design for a future merge.
- Prefer small Seeding-local duplication over coupling to the stable SEO AI pipeline.
- Shared only when already generic: queue/HTTP/provider clients/DB/cache + existing Canonical routing via Social comment task shell.
- Canonical write-up: `docs/modules/SEEDING.md` §8.

## EDITOR WIDGET LOCKS

**SEO remains unlocked for active development.** All other registered Article Editor widgets are locked (manifest-driven; guard does not hard-code IDs).

Typical locked ids include: `featured`, `images`, `gallery`, `reviews`, `links`, `cta`, `vocabulary`, `faq`, `ai-chat`, `publishing`, `status`.

Before editing Editor code, check the widget lock manifest:
`content/editor-widget-locks.json`

Commands (from `omnichannel-client`):

- `npm run check:editor-widget-locks`
- `npm run widget-lock -- status`
- `npm run widget-lock -- unlock <id>`
- `npm run widget-lock -- lock <id>`

Do not refactor, rename, localize, clean up, or indirectly modify
locked widget behavior.

`status` currently displays **Trạng thái**.
Its missing locale is known and intentionally frozen.

Manifest = policy. Guard = generic enforcement. Never hard-code widget IDs in the guard.
