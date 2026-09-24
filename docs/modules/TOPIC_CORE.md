# Topic Core (site-scoped)

> Last verified: 2026-09-24 (Keyword MCP Type 2 closure docs pointer)

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

## Automatic Topic seed evidence

```
curated Domain Link List  (seo_domain_prompt_context.links — keyword → URL)
+
verified product_cat      (all depths: root + child + nested)
```

| Source | Semantics |
|---|---|
| **Curated Domain Link List** | Explicit keyword→URL intent from `SiteDomainPromptContextService` |
| **Verified product_cat** | Taxonomy identity via `VerifiedProductCatLinkSource` / `SiteMcpProductCatIdentity`; Manufacturer (`production`) / Ecommerce (`e-commerce`) only |
| **Site Sync Link Catalog** | Inventory only (`SiteLinkCatalogCapability::effectiveLinks` = WordPress ∪ Manual − Excluded) — **NOT** automatic Topic seed evidence |

**Membership candidate pool ≠ Topic seed pool.**  
Candidates for attach/rescan: existing current-site Dictionary keywords (`loadTopicCandidateKeywords`) — do not invent Keywords; do not require `is_seo_keyword=true`.

Manual Topics: `seo_topics.source=manual`; may have 0 members; survive recluster by `topic_id` without synthetic Keyword seeds.

Precedence when the same Keyword appears in both seed sources: `link_list` > `product_cat`.

Topic seed resolution does **not** call `SiteLinkPolicyResolver::forKeyword()` — Keyword policy and Topic seeds stay independently versioned. See [SITE_LINK_POLICY.md](./SITE_LINK_POLICY.md).

## Not in v1

- No `cluster_key`
- No `seo_topic_labels` / `seo_topic_aliases`
- No `display_name` / `normalized_name` / `folded_name` business columns on Topics
- No Focus Keyword ⇒ Topic rule
- No dual-write / compatibility adapters / legacy table reads

## Recluster

Explicit only: `php artisan seo:topics-recluster {site_id} [--sync]`

Read-only seed impact preview (no Topic mutation):

`php artisan seo:topics-seed-preview {site_id}`

Flow:

1. Ensure `seo_site_keywords`
2. Seeds (`TopicSeedResolver`):
   - Curated Domain Link List (`seo_domain_prompt_context.links`)
   - All-depth verified product_cat for Manufacturer / Ecommerce
3. Cluster + persist Topics / memberships
4. Rebuild DNA

Does **not** auto-run from migrations. Does **not** mutate other sites.  
Deploying seed-source code does **not** auto-recluster or delete Topics — operators must preview then explicitly recluster.

## Dissolve

Deletes Topic DNA + memberships + `seo_topics` row. Preserves `seo_site_keywords`, `keywords`, articles, link catalog, taxonomy facts.

## Website type

See [WEBSITE_TYPE.md](./WEBSITE_TYPE.md) — Manufacturer UI = `production` internal key.

## Linked articles

Always filter link maps by `sourceArticle.site_id`. Never `WHERE keyword_id IN (...)` alone.

## Keyword MCP

Topic Core backs:

- **Type 1 — Landscape** (`keywords.mcp.v2` / `KeywordLandscapeGateway`) — site Topic landscape for approved consumers only  
- **Type 2 — Relationship** (`keyword.relationship` / `keyword.relationship.v1`) — **CLOSED — v1**; one-keyword on-demand graph; does not write `seo_mcp_source_snapshots`

Canonical contract (client): `omnichannel-client/docs/contracts/KEYWORD_MCP.md`.  
Deferred Type 2 items: `omnichannel-client/bugs/keyword-mcp-type-2-deferred.md`.
