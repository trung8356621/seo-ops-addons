# Website Type mapping

**Mandatory reference for developers and AI agents.**

Site website type is persisted on Site meta key `seo_domain_type`.

| UI label (Domain form) | Internal persisted key | Notes |
|------------------------|------------------------|-------|
| News | `news` | |
| Manufacturer | `production` | Legacy/internal compatibility key — **not** a UI label |
| Ecommerce | `e-commerce` | Aliases accepted in readers: `ecommerce`, `e_commerce` |

## Rules

1. **Do not rename** the persisted key `production` in Topic Core (or related) batches — many Site MCP / catalog strategies still key off `production` → `production_catalog`.
2. **Never infer** that `production` is still shown to users. UI must use **Manufacturer** via `DomainListPresentation::websiteTypeFormOptions()` / `websiteTypeLabel()`.
3. Topic seed eligibility for root `product_cat` applies when the site is Manufacturer (`production`) or Ecommerce (`e-commerce` + aliases).

## Code SSOT

- Form options / labels: `search-foundation/src/Support/DomainListPresentation.php`
- Contract test: `site-sync/tests/Unit/DomainListPresentationContractTest.php`
