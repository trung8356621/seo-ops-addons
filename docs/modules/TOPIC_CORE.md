# Topic Core (site-scoped)

> Last verified: 2026-09-18

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
2. Seeds (`TopicSeedResolver` — **not** `SiteLinkPolicyResolver` yet):
   - **Site Sync Link Catalog** via `SiteLinkCatalogCapability::effectiveLinks` = WordPress ∪ Manual − Excluded  
     (broad inventory — **not** curated Domain Link List; title may fallback as phrase)
   - **All-depth verified product_cat** for Manufacturer (`production`) / Ecommerce (`e-commerce`), via Site MCP discovery + `SiteMcpProductCatIdentity` fail-closed rules (`parent_term_id` present; `0` = root, `>0` = nested)
3. Cluster + persist Topics / memberships
4. Rebuild DNA

Does **not** auto-run from migrations. Does **not** mutate other sites.

**Phase 2:** whether Topic should consume `SiteLinkPolicyResolver` (and which sources) is **unresolved**. See [SITE_LINK_POLICY.md](./SITE_LINK_POLICY.md). Do not “fix” Topic seeds under Site Link Policy Phase 1.

## Dissolve

Deletes Topic DNA + memberships + `seo_topics` row. Preserves `seo_site_keywords`, `keywords`, articles, link catalog, taxonomy facts.

## Website type

See [WEBSITE_TYPE.md](./WEBSITE_TYPE.md) — Manufacturer UI = `production` internal key.

## Linked articles

Always filter link maps by `sourceArticle.site_id`. Never `WHERE keyword_id IN (...)` alone.
