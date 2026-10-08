# Industry Group

> Owner: `search-foundation` (read model) · Runtime bridge: `search-intelligence`  
> Authoring source: Industry Context → Match & Research revision  
> Stable project term: **Industry Group**

## Definition

An **Industry Group** is a visible, read-only semantic/concept group projected from the active Industry Context Match & Research taxonomy.

It is normal Match & Research knowledge shown in the shared Match & Research UI.

It is **not**:

| Concept | Relationship |
|---|---|
| Keyword | Never inserted into the Keyword dataset |
| Keyword Group | Separate clustering construct |
| Topic | Separate Topic Core construct |
| Tag | Not Keyword Tag authority; future multi-membership is evidence, not UI tags |

## Lifecycle

```text
Industry Match revision (authoring)
    ↓
MatchResearchResource (registry)
    ↓
Industry Group (read-only projection / IndustryGroupProvider)
    ↓
IndustryGroupSemanticDefinition
    ↓
ConceptMatchingClient → POST /v1/concept-matches/analyses
    ↓
typed Industry Group match evidence
    ↓
(deferred) keyword ↔ Industry Group membership → Keyword Grouping → Topic
```

## Runtime path (V1 bridge)

```text
IndustryGroupProvider
    ↓
IndustryGroupSemanticMatcher
    ↓
ConceptMatchingClient (wraps SemanticAnalyticsClient)
    ↓
seo-ops-semantic Concept Matching
    ↓
IndustryGroupMatchEvidence
```

### V1 matching policy

| Setting | Value |
|---|---|
| `matching_strategy` | `hybrid` |
| `semantic_fallback` | `false` |

**Reason:** short Industry Group anchors (e.g. `balo`) cannot rely on cosine similarity alone — unrelated strings can score similarly. Deterministic lexical evidence is authoritative when explicit terms exist. Semantic cosine scores are retained as calibration evidence only and do **not** create membership by themselves in V1.

No baked production `min_positive_score` (0.55 / 0.70 / 0.80).

### Deferred

- Keyword ↔ Industry Group membership persistence
- Treating Industry Groups as Keyword Group output or a whitelist
- Topic clustering integration

Suggested memberships (`suggested_match=true`) may be sent as optional positive evidence on `POST /v1/keyword-groups/analyses`. They do not whitelist keywords and do not override lexical conflict. See `docs/modules/KEYWORD_GROUPING.md`.

## V1 qualifying group types

Exactly these taxonomy groups:

- `products`
- `product_families`
- `materials`
- `services`
- `audiences`
- `use_cases`
- `features`
- `adjacent_products`

## Explicit exclusions

Still Match & Research knowledge, **not** Industry Groups:

- `generic_cores` (topic_rules)
- `service_intent_terms` (topic_rules)
- `aliases`
- `ambiguities`

## Storage

**No** `industry_groups` database table.

Industry Groups are projections of:

- active Industry Match & Research revision
- MatchResearch registry
- locale overlays

When the active Match revision changes, Industry Groups change with it. No sync job.

Stale / disabled groups are skipped by the runtime matcher (`stale_groups_skipped` / disabled diagnostics). Empty active set → no Python call.

## Locale

Identity is language-neutral (`industry.{group}.{normalized}.{suffix}`).

- Keys are never derived from translated labels.
- Requested locale may use a localization overlay when present.
- Missing overlay → source-locale fallback with explicit `localized=false` / `effective_locale=source_locale`.
- No auto-translation / LLM.

## Semantic input

`IndustryGroupSemanticDefinition` prepares transport examples:

`name` + `aliases` + `positive_examples` (deduped) + `match_mode`

Laravel does **not** run similarity, fuzzy, token-overlap, or LIKE matching. Matching authority is `seo-ops-semantic`.

Diagnostic CLI (no DB writes):

```bash
php artisan semantic:industry-groups:match --industry=bags --site=4 --locale=vi \
  --text="balo học sinh cấp 1" --text="Zalo 0909983833"
```

## Match & Research → Industry → Live Industry Match

This is the canonical manual diagnostic UI for Industry Group matching.

```text
Industry Groups
    ↓
IndustryGroupSemanticMatcher
    ↓
Python Concept Matching
    ↓
lexical + semantic evidence
    ↓
suggested result
```

The page calls `IndustryGroupSemanticMatcher::match()` for the selected site and active Industry Context. It does not call `MatchRuleMatcher` and does not fall back to the old deterministic matcher when Python is unavailable.

A row is a V1 membership candidate only when `suggested_match=true`. Semantic similarity (`positive_max`) is calibration evidence. A high similarity with `suggested_match=false` is not a match.

"Show all evidence" lists every Industry Group row returned by Python, including `suggested_match=false`.

Specific empty states stay distinct: `match_revision_inactive`, `no_match_revision`, `match_revision_stale`, `no_taxonomy_groups`, `no_active_industry_groups`. Semantic disabled and Python unavailable are shown as errors, not as "No Industry Groups".

## Code

| Piece | Location |
|---|---|
| Types | `search-foundation` `Enums/IndustryGroupType` |
| DTO | `DTO/IndustryGroup/IndustryGroup` |
| Semantic DTO | `DTO/IndustryGroup/IndustryGroupSemanticDefinition` |
| Provider | `Contracts/IndustryGroup/IndustryGroupProvider` |
| Read model | `Services/IndustryGroup/IndustryGroupReadModel` |
| Concept Matching client | `search-intelligence` `Services/Semantic/ConceptMatching/*` |
| Matcher | `Services/IndustryGroup/IndustryGroupSemanticMatcher` |
