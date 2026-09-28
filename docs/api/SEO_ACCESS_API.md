# SEO Access API Specification (v1)

## Overview

The SEO Access API provides capability-isolated, read-only temporary access to SEO intelligence resources. Access is granted via short-lived temporary access tokens minted through the service credential authentication endpoint.

Tokens exist in two strictly segregated scopes:
1. **Site Scope (`site`)**: Bound to a specific managed site (`site_id`). Grants access to site-specific resources (`/site`, `/keywords`, `/gsc`).
2. **Global Scope (`global`)**: Not bound to a single site. Exclusively grants access to cross-site topology aggregates (`/site-network`), strictly forbidden from querying site-specific resources.

---

## 1. Token Minting

### Endpoint
`POST /api/v1/services/seo/access`

### Authentication
`Authorization: Bearer <service_api_key>` (requires `seo:read` scope)

### Site-Scoped Token Minting Request
```json
POST /api/v1/services/seo/access
Content-Type: application/json

{
  "site_id": 7,
  "ttl": 900
}
```

#### Response (200 OK)
```json
{
  "data": {
    "scope": "site",
    "site_ref": "site:7",
    "access_url": "https://api.omnichannel.example/api/v1/access/access_tmp_01abc...",
    "expires_at": "2026-09-28T09:15:00+00:00"
  }
}
```

### Global-Scoped Token Minting Request
```json
POST /api/v1/services/seo/access
Content-Type: application/json

{
  "scope": "global",
  "ttl": 900
}
```

#### Response (200 OK)
```json
{
  "data": {
    "scope": "global",
    "site_ref": null,
    "access_url": "https://api.omnichannel.example/api/v1/access/access_tmp_02xyz...",
    "expires_at": "2026-09-28T09:15:00+00:00"
  }
}
```

---

## 2. Global Site Network Resources

Global temporary access tokens (`scope: "global"`) allow reading the cross-site link graph between managed sites.

### 2.1 Overview Graph
`GET /api/v1/access/{globalToken}/site-network`

#### Response (200 OK)
```json
{
  "data": {
    "schema": "seo.site_network.v1",
    "sites": [
      {
        "site_ref": "site:1",
        "site_id": 1,
        "domain": "alpha.example.com"
      },
      {
        "site_ref": "site:2",
        "site_id": 2,
        "domain": "beta.example.com"
      }
    ],
    "edges": [
      {
        "source_site_ref": "site:1",
        "target_site_ref": "site:2",
        "article_link_count": 14,
        "source_article_count": 6,
        "target_article_count": 4,
        "source_keyword_count": 8,
        "keyword_relation_count": 10
      }
    ],
    "note": "Direction preserved. A→B and B→A are separate edges."
  }
}
```

### Metric Semantics & Directionality
- **Directionality**: Directed graph. An edge from `Site A -> Site B` represents hyperlinks originating in published articles on Site A pointing to Site B. `A -> B` and `B -> A` are separate edges and never collapsed.
- **`article_link_count`**: Total number of hyperlinks from articles in `source_site` to `target_site`.
- **`source_article_count`**: Number of distinct source articles on `source_site` that contain at least one link to `target_site`.
- **`target_article_count`**: Number of distinct target articles on `target_site` linked from `source_site`.
- **`source_keyword_count`**: Number of distinct source keywords (from `seo_link_maps.keyword_id`) originating the links.
- **`keyword_relation_count`**: Number of distinct keyword-to-keyword relationships where the target article has a resolved focus keyword (`TargetKeywordResolver`).

### 2.2 Site-Pair Topics Drilldown
`GET /api/v1/access/{globalToken}/site-network/topics?source_site={sourceSiteId}&target_site={targetSiteId}`

#### Query Parameters
- `source_site` (integer, required): ID of the source site.
- `target_site` (integer, required): ID of the target site.

#### Response (200 OK)
```json
{
  "data": {
    "schema": "seo.site_network.topics.v1",
    "source_site_ref": "site:1",
    "target_site_ref": "site:2",
    "topics": [
      {
        "topic_id": 42,
        "topic_ref": "topic:42",
        "name": "Organic Coffee",
        "cross_site_link_count": 7
      }
    ]
  }
}
```

---

## 3. Scope Isolation & Security Guarantees

### 3.1 Strict Route Separation (403 Forbidden)
Global tokens are strictly prohibited from accessing site-bound resources. Attempting to use a global token on site-bound endpoints returns a `403 Forbidden` response:

```json
GET /api/v1/access/{globalToken}/site
GET /api/v1/access/{globalToken}/keywords
GET /api/v1/access/{globalToken}/gsc

HTTP/1.1 403 Forbidden
Content-Type: application/json

{
  "error": {
    "code": "service_api_forbidden",
    "message": "This resource requires a site-scoped access token."
  }
}
```

### 3.2 Read-Only Invariant
All `/api/v1/access/*` endpoints are strictly read-only. No write operations, state mutations, or cache poisoning are permitted.

### 3.3 Agent Runtime Global Retrieval Guard
Agent Runtime global retrieval remains intentionally unsupported. `SeoAccessExecutor` enforces site scope verification and rejects global scope requests with an explicit exception.
