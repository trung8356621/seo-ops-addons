# Topic Core (site-scoped)

Owner: `search-intelligence/`  
Capability: `search.topic`

## Architecture

```
keywords                 = global keyword dictionary
seo_site_keywords        = per-site classification (NO topic membership)
seo_topics               = per-site Topic entity (name = sole name SSOT)
seo_topic_keywords       = membership (UNIQUE site_id + keyword_id)
seo_topic_keyword_dna    = DNA of keyword within Topic
```

**Invariant:** Topic, membership, DNA, locks, counts, linked articles, MCP attribution, and planning downstream are always `site_id`-scoped. Same global `keyword_id` may belong to Topic A on site 3 and Topic B on site 6, or be unassigned on site 7.

## Not in v1

- No `cluster_key`
- No `seo_topic_labels` / `seo_topic_aliases`
- No `display_name` / `normalized_name` / `folded_name` business columns on Topics
- No Focus Keyword ⇒ Topic rule
- No dual-write / compatibility adapters / legacy table reads

## Recluster

Explicit only: `php artisan seo:topics-recluster {site_id} [--sync]`

Flow:

1. Ensure `seo_site_keywords`
2. Seeds:
   - **Link List SSOT:** `SiteLinkCatalogCapability::effectiveLinks` = WordPress ∪ Manual − Excluded
   - **Root product_cat** for Manufacturer (`production`) / Ecommerce (`e-commerce`), via Site MCP discovery + `SiteMcpProductCatIdentity` fail-closed rules
3. Cluster + persist Topics / memberships
4. Rebuild DNA

Does **not** auto-run from migrations. Does **not** mutate other sites.

## Dissolve

Deletes Topic DNA + memberships + `seo_topics` row. Preserves `seo_site_keywords`, `keywords`, articles, link catalog, taxonomy facts.

## Website type

See [WEBSITE_TYPE.md](./WEBSITE_TYPE.md) — Manufacturer UI = `production` internal key.

## Linked articles

Always filter link maps by `sourceArticle.site_id`. Never `WHERE keyword_id IN (...)` alone.
