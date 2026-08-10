# seo-content-ai-compat — freeze note (Task 13)

> Status: **FROZEN**  
> Path: `omnichannel-addons/seo-content-ai-compat`

## Purpose

Compatibility shell only: Filament panel bootstrap, views, lang, routes, Settings, legacy slug `seo-content-ai`.

Namespace `App\Addons\SeoContentAi\*` may remain for consumers. **No new business logic.**

## Keep

- Providers / panel registration
- Blade views still referenced by SEO / Performance Hub / Filament
- Lang / config / Settings used by panel
- Routes still registered for compat surfaces

## Do not

- Add Models/Services/JS business here
- Move views “for aesthetics” without caller proof
- Point Vite business entrypoints through this package

## Migration path

No `database/migrations` under compat (empty/absent). Peer migrations live in owner addons. `automation:migrate` full path points at compat migrations dir and no-ops if missing.

## Ownership

See `SEO_CONTENT_AI_COMPAT_SHELL.md` and `OWNERSHIP_MAP.json`.
