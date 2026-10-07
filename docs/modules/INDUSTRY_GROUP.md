# Industry Group

> Owner: `search-foundation` (read model) · Authoring source: Industry Context → Match & Research revision  
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
Industry Group (read-only projection)
    ↓
future seo-ops-semantic Concept Matching
    ↓
keyword ↔ Industry Group evidence
    ↓
Keyword Grouping → Topic
```

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

## Locale

Identity is language-neutral (`industry.{group}.{normalized}.{suffix}`).

- Keys are never derived from translated labels.
- Requested locale may use a localization overlay when present.
- Missing overlay → source-locale fallback with explicit `localized=false` / `effective_locale=source_locale`.
- No auto-translation / LLM.

## Semantic input (future)

`IndustryGroupSemanticDefinition` prepares transport examples:

`name` + `aliases` + `positive_examples` (deduped)

Laravel does **not** run similarity, fuzzy, token-overlap, or LIKE matching. Semantic meaning belongs to `seo-ops-semantic`.

## Code

| Piece | Location |
|---|---|
| Types | `Enums/IndustryGroupType` |
| DTO | `DTO/IndustryGroup/IndustryGroup` |
| Semantic DTO | `DTO/IndustryGroup/IndustryGroupSemanticDefinition` |
| Provider | `Contracts/IndustryGroup/IndustryGroupProvider` |
| Read model | `Services/IndustryGroup/IndustryGroupReadModel` |
