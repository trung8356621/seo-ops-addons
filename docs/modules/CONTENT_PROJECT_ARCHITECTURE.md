# Content Project architecture (site / domain ownership)

> Status: Canonical (SSOT for domain ownership)  
> Owner: `content-projects`  
> Last verified: 2026-09-21

Agents and humans must treat this document as the ownership boundary for Content Projects.
Operational routes, run engine, and UI details live in the client module guide
(`omnichannel-client/docs/modules/CONTENT_PROJECTS.md`) and AI integration note
(`omnichannel-client/docs/architecture/CONTENT_PROJECT_AI_INTEGRATION.md`).
When those guides conflict with this file on **site/domain ownership**, **this file wins**.

---

## 1. Entity ownership

### SeoProject (execution / planning container)

- Groups **month**, writer/user assignment, project status/kind, and lifecycle.
- **MAY** have `site_id = null` (domain-neutral).
- Shared Draft and monthly execution projects can both be domain-neutral.
- `seo_projects.site_id` is **legacy / compatibility metadata** where present.
- **NOT** the canonical domain owner.
- One project **MAY** contain tasks from multiple sites.

### SeoProjectTask (content / domain ownership unit)

- Canonical content unit for domain attribution.
- **`task.site_id` owns site/domain**.
- Different tasks in one project **MAY** have different `site_id` values.
- Moving a task between projects **MUST preserve** `task.site_id`.

---

## 2. Required invariants

1. Project list is **domain-neutral** (do not filter the list by assuming one project domain).
2. Monthly execution projects are **not required** to be site-specific.
3. Moving a task across projects **MUST NOT** change `task.site_id`.
4. Target project eligibility for within-month move =
   same planning month + valid execution project + not source/archive/draft + packing/capacity gates.
   It **MUST NOT** require:
   - `source.project.site_id == target.project.site_id`
   - target project “contains” the same site
   - intersection of resolved project item site sets
5. Do **not** infer target eligibility from `target.project.site_id` unless a feature
   explicitly documents a **site-scoped exception**.

---

## 3. Site-scoped exceptions

Some operations still need an explicit working Site. That Site comes from:

- **explicit working site context** (Planner Global Domain / agent `site_ref` / command `siteId`), or
- **`task.site_id`**

**Not** from assuming the entire Project belongs to one domain.

Typical exceptions:

| Operation | Site source |
|-----------|-------------|
| SEO Audit / planner | Explicit working Site |
| Generation for an individual item | `task.site_id` |
| Publishing / WP destination | Item / article / publish target site |
| Site ACL / tenant access | Accessible sites of the actor; for domain-neutral projects, item site set |

---

## 4. Legacy compatibility

- Column `seo_projects.site_id` **still exists**. Do not migrate it away in ordinary work.
- Some legacy rows may contain a positive `site_id`.
- New / domain-neutral projects may leave it `NULL`.
- Code **must not** treat `null` `project.site_id` as invalid project ownership.
- Legacy `project.site_id` must **never override** an existing positive `task.site_id`.
- Where a helper still accepts `project.site_id`, treat it as **fallback for new rows / bootstrap only**.

---

## 5. Anti-patterns (do not reintroduce)

- “Project owns the domain.”
- Filtering move targets by `project.site_id`.
- Rejecting moves because source/target project sites differ.
- Forcing all synced tasks onto `project.site_id`.
- Agent / read gates that fail solely because execution `project.site_id` is null.
- UI copy that presents `$project->site` as authoritative when `project.site_id` is null.
