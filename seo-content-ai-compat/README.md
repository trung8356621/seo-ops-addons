# SeoContentAi — COMPATIBILITY SHELL ONLY

> **Status:** Compatibility shell (not an active business owner)  
> **Do not** put new domain logic here.  
> **DELETE entire addon?** **NO** — Filament still loads ~239 Blade views under `resources/views/` via namespace `seo-content-ai::`, plus panel/bootstrap wiring.

Active business MUST live in peer addons under `/addons`:

| Peer addon | Owns |
|------------|------|
| `addons/content` | Articles, editor persistence, document model |
| `addons/seo` | SEO panel pages, SEO score/meta, **GlobalSeoBar Livewire** |
| `addons/media` | Media library, watermark, image tools |
| `addons/wordpress` | WP bridge write/sync + **route file** `routes/seo-wp-bridge.php` |
| `addons/publishing` | Publish queue / schedule |
| `addons/content-projects` | Projects/tasks + **route file** `routes/api-v1.php` |
| `addons/search-intelligence` | GSC / performance intelligence |
| `addons/ai-prompt` | Prompts, prompt hooks, AI result UX |

Also related: `addons/search-foundation`, `addons/agent`, `addons/site-sync`, `addons/commerce`, `addons/social`.

## This pass (Task 8)

| Moved | To |
|-------|-----|
| `resources/prompt-hooks/**` | `addons/ai-prompt/resources/prompt-hooks/**` (loaders retargeted: `dirname` → addon root) |
| `scripts/check-editor-cycles.cjs` | `addons/content/scripts/check-editor-cycles.cjs` (`package.json` + import-graph test) |

| Deleted | Why |
|---------|-----|
| `demo-seo-editor.js` | Orphan, zero callers |
| Empty `resources/js/**`, `resources/css`, empty Extension Registry/Resolvers/Contracts | Zero files after prior JS/PHP moves |
| `Extensions/.gitkeep` | Placeholder; discovery scans `addons/agent/src/Extension` |

| Deferred | Why |
|----------|-----|
| `resources/views/**` (~239) | Filament still `->view('seo-content-ai::…')` |
| `lang/**` / `config/**` | Namespace/`seo-content-ai.*` still merged from shell |
| `Providers/SeoPanelProvider.php` + fat `SeoContentAiServiceProvider.php` | Still `panel_provider` / DI bootstrap |

**Extension manifests:** moved to `addons/agent/src/Extension/Builtin/{AiProviders,ContentPipelines,LocalSeo,Wordpress}/plugin.json` (discovery root). Owner copies also under ai-prompt/seo/wordpress. **Not** under SeoContentAi anymore.

**Views still pending relocate.** Vite inputs already retargeted to peer addons (`@vite('addons/…')`).

## Remaining file inventory + callers

**Count (Task 8 end):** ~263 files (239 views + 24 non-view).

### KEEP / bootstrap (cannot delete)

| Path | Caller / role |
|------|----------------|
| `addon.json` | Addon discovery; `provider` + `panel_provider` + legacy slug `seo-content-ai` |
| `SeoContentAiServiceProvider.php` | Compat bootstrap: DI, `mergeConfigFrom`, views NS, schedule, observers |
| `Providers/SeoPanelProvider.php` | Filament SEO panel, Livewire register, translations, API route group load |
| `routes/api.php` | Shim → peer route files |
| `routes/web.php` | Stub comment only |
| `README.md` | This doc |
| `README_ADDON_SEOCONTENTAI.md` | Legacy longform — **not** architecture SoT |
| `tests/Compat/UsesSeoDatabase.php` | PHPUnit trait for peer addon tests |
| `database.local.php.example` | Legacy DB example |
| `Settings.php` | SiteService defaults for SEO service entity |

### Config / lang (transitional)

| Path | Note |
|------|------|
| `config/**` | Merged as `seo-content-ai.*` |
| `lang/{en,vi}/**` | Namespace `seo-content-ai::` via `SeoPanelProvider` |

### Extension manifests (moved out)

Runtime discovery: `addons/agent/src/Extension/Builtin/*/plugin.json`.  
SeoContentAi no longer hosts Extension tree.

### Views (PENDING relocate — Filament still loads)

Namespace: `seo-content-ai::` via `loadViewsFrom`. **Do not delete** until Filament registrations move namespaces.

## Target end-state (not yet)

Ideal shell: `addon.json` + thin ServiceProvider adapter + README + Compat trait + (temporarily) views.  
**Not reached yet:** SeoPanelProvider + fat ServiceProvider + config/lang still required.

## Canonical docs

Start at [`docs/README.md`](../../../docs/README.md). Inventory JSON: [`docs/architecture/SEOCONTENTAI_CUTOVER_INVENTORY.json`](../../../docs/architecture/SEOCONTENTAI_CUTOVER_INVENTORY.json).
