# Site Link Policy

> Status: Canonical (Phase 1)  
> Last verified: 2026-09-18  
> Owner: `search-foundation/` (`SiteLinkPolicyResolver`)  
> Related: [WEBSITE_TYPE.md](./WEBSITE_TYPE.md), [TOPIC_CORE.md](./TOPIC_CORE.md)

Read-only **consumer policy / composition** for site links.

`SiteLinkPolicyResolver` does **not** own storage, WP sync, catalog inventory, Keyword persistence, or Topic seeds.

## Not the same things

| Concept | Owner | Semantics |
|---|---|---|
| **Domain Link List** | `SiteDomainPromptContextService` (`seo_domain_prompt_context.links`) | Curated keyword → URL list |
| **Site Sync Link Catalog** | `SiteLinkCatalogCapability::effectiveLinks()` | WordPress ∪ Manual − Excluded (broad inventory) |
| **Verified product_cat** | `SiteMcpProductCatIdentity::normalizeVerified()` | Taxonomy identity SSOT |
| **Site Link Policy** | `SiteLinkPolicyResolver` | Consumer composition / filter / dedupe only |

**Domain Link List ≠ Site Sync Link Catalog.**

## Runtime API (Phase 1)

```php
SiteLinkPolicyResolver::forKeyword(Site $site): array
SiteLinkPolicyResolver::forArticleEditor(Site $site): array
```

| Consumer | Composition |
|---|---|
| Keyword (`forKeyword`) | Domain Link List + all verified product_cat (**production / e-commerce** only); other site types = Domain Link List only |
| Article Editor (`forArticleEditor`) | Domain Link List + all verified product_cat (all depths) + main domain |
| Site MCP | **Unchanged** — root product_cat (`parent_term_id === 0`) → Important Pages in `SiteMcpGenerator` |
| Topic | Uses curated Domain Link List + all-depth product_cat directly in `TopicSeedResolver` — **not** via `forKeyword()` / `forArticleEditor()` (independent Topic seed contract). See [TOPIC_CORE.md](./TOPIC_CORE.md) |

Record shape (minimal):

```text
keyword, url, source (domain_link_list | product_cat | main_domain),
taxonomy?, term_id?, parent_term_id?
```

Dedupe identity: normalized keyword. Precedence: `domain_link_list > product_cat > main_domain`.

## Supporting classes

| Class | Role |
|---|---|
| `VerifiedProductCatLinkSource` | Load verified product_cat rows (local articles → live export fallback) via Identity SSOT |
| `EffectiveDomainLinkResolver` | Thin Editor adapter → `forArticleEditor()` (legacy `custom` / `link` shape) |
| `DomainLinkListEditorService` | Editor presentation + body phrase filter + usage counts |
| `DomainLinkListKeywordSyncService` | Materialize `forKeyword()` into Keyword rows; **never** write `product_cat` into prompt `links` |

## Critical invariant

```text
verified product_cat → policy → Keyword materialization
  MUST NOT write into seo_domain_prompt_context.links
```

Provenance meta: `site.{id}.link_policy_source` = `domain_link_list` | `product_cat`.  
Reverse path `keyword.domain_link_list.sync` skips `product_cat`.

## Phase 2A (Topic seed sources)

Settled: Topic automatic seeds = curated Domain Link List + verified product_cat all-depth.  
Site Sync catalog is inventory only (not Topic seeds).  
Topic does **not** call `SiteLinkPolicyResolver` — it reads the same canonical components independently so Keyword policy changes do not silently reshape Topics.  
No public `forTopicSeed()` in Phase 2A.

## Tests

- `search-foundation/tests/Unit/SiteLink/SiteLinkPolicyResolverTest`
- `search-foundation/tests/Unit/SiteLink/DomainLinkListKeywordContaminationGuardTest`
- `seo/tests/Unit/EffectiveDomainLinkResolverTest`
- `seo/tests/Unit/DomainLinkListEditorServiceContractTest`
