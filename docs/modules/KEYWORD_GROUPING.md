# Keyword Grouping

> Owner: `search-intelligence`  
> Stable path: inventory → Eligibility Gate → Python Keyword Grouping

## Flow

```text
Keyword inventory (KeywordGroupCandidateLoader)
    ↓
structural annotation
    ↓
Keyword Grouping Eligibility Gate (KeywordGroupingEligibilityGate)
    ├─ eligible → KeywordGroupSemanticRefreshService
    │              → POST /v1/keyword-groups/analyses
    └─ excluded → not sent to grouping
```

`KeywordGroupCandidateLoader` is the inventory source. It does not decide admission.

The gate decides whether one candidate may enter semantic grouping. Later semantic Match & Research evidence and consumer policies can be added on this boundary without reloading keywords.

## V1 exclusion

Automatically excluded:

- `contact_like` (email or phone-like structure; `email_like` / `phone_like` are internal evidence)
- `url_like` (`http://`, `https://`, or `www.` host)

Annotation only, still eligible:

- `question_like` (question mark or a small set of question cues)

Manual groups and locked groups stay protected. The gate does not delete, move, or rewrite those memberships. It only filters candidates that participate in semantic refresh.

If every non-protected candidate is excluded, Python is not called. Existing non-protected semantic groups are removed. Protected groups stay.

## Deferred

- `sentence_like`
- CTA / noise semantic exclusion
- off-context exclusion
- consumer-policy exclusions (`topic.exclude_from_recluster`)
- Industry Group evidence

No Industry Group match does not make a keyword invalid. `IndustryGroupSemanticMatcher` is not an eligibility whitelist.

Diagnostic (no DB writes, no concept matching):

```bash
php artisan semantic:keyword-eligibility --text="Zalo: 0909983833" --text="mua balo ở đâu?"
```
