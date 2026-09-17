# Website Type mapping

**Mandatory reference for developers and AI agents.**

> Last verified: 2026-09-18

Site website type is persisted on Site meta key `seo_domain_type`.

| UI label (Domain form) | Internal persisted key | Notes |
|------------------------|------------------------|-------|
| News | `news` | |
| Manufacturer | `production` | Legacy/internal compatibility key — **not** a UI label |
| Ecommerce | `e-commerce` | Aliases accepted in readers: `ecommerce`, `e_commerce` |

## Rules

1. **Do not rename** the persisted key `production` in Topic Core (or related) batches — many Site MCP / catalog strategies still key off `production` → `production_catalog`.
2. **Never infer** that `production` is still shown to users. UI must use **Manufacturer** via `DomainListPresentation::websiteTypeFormOptions()` / `websiteTypeLabel()`.
3. **product_cat eligibility by consumer** (Manufacturer / Ecommerce = `production` / `e-commerce` + aliases):
   - **Site MCP Important Pages:** verified **root only** (`parent_term_id === 0`)
   - **Site Link Policy Keyword / Editor + Topic seeds (current):** verified product_cat at **all depths** (root + child + nested)
   - Missing / null parent metadata is **never** treated as root — see `SiteMcpProductCatIdentity`

## Related

- Site Link Policy (composition): [SITE_LINK_POLICY.md](./SITE_LINK_POLICY.md)
- Topic Core seeds: [TOPIC_CORE.md](./TOPIC_CORE.md)

## Code SSOT

- Form options / labels: `search-foundation/src/Support/DomainListPresentation.php`
- Contract test: `site-sync/tests/Unit/DomainListPresentationContractTest.php`
