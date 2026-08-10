# omnichannel-addons

Peer-addon monorepo — **one Git repo**, many peer folders. No parent/child hierarchy.

## Peers
search-foundation · seo · search-intelligence · ai-prompt · content · content-projects · media · wordpress · publishing · site-sync · agent · social · commerce · seo-content-ai-compat

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
| WP sync | `wordpress/` |
| Publishing | `publishing/` |
| Content Project | `content-projects/` |
| AI/prompt | `ai-prompt/` |
| Site Sync | `site-sync/` |
| Agent/MCP | `agent/` |
