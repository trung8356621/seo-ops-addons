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
| **Content Project architecture (domain ownership)** | `docs/modules/CONTENT_PROJECT_ARCHITECTURE.md` — Project is domain-neutral; **item/task.site_id** is canonical site ownership |
| AI/prompt | `ai-prompt/` |
| Site Sync | `site-sync/` |
| Agent/MCP | `agent/` |
| Social Profile / manual share | `social/` |
| Seeding Topic V2 / Link Intelligence | `seeding/` |
| **Site Link Policy** (consumer composition) | `search-foundation/` — see `docs/modules/SITE_LINK_POLICY.md` |

## Website type (Manufacturer ≠ production)

UI label **Manufacturer** persists as internal key **`production`**. Ecommerce persists as **`e-commerce`**.  
Canonical doc: `docs/modules/WEBSITE_TYPE.md`. Do not rename `production` casually; do not treat `production` as a UI label.

## Site links (do not conflate)

| Concept | Meaning |
|---------|---------|
| Domain Link List | Curated `seo_domain_prompt_context.links` |
| Site Sync Link Catalog | `effectiveLinks()` = WP ∪ Manual − Excluded |
| Site Link Policy | Read-only composition for Keyword / Editor (`SiteLinkPolicyResolver`) |

Canonical: `docs/modules/SITE_LINK_POLICY.md`. Topic seeds = curated Domain Link List + all-depth product_cat (not Site Sync catalog).

## Seeding AI vs shared AI stack

Seeding Gen Comment **owns** MCP context, Flexible Seeding UI state, and Seeding debug history.

It **must use** the shared ai-prompt Interactive executor + Routing Policy (`quick_free` default) — not a Seeding-local provider/HTTP/routing planner.

Do **not**:
- call OpenRouter/Gemini/DeepSeek from Seeding
- duplicate `AiCandidatePlanner` / health / cooldown in Seeding
- hold DB transactions open during provider calls

Shared Prompt binding (`seeding.comment.generate`) + AI History (Prompt Result / routing attempts) are intentional.
Seeding-local duplication is preferred only for Seeding domain concerns (MCP, seed batches, reports), not for AI routing.

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
