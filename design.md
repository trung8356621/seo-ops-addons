# SEO Ops Dashboard & Statistics Design

## Product Intent

SEO Ops tách rõ hai bề mặt:

1. **Dashboard** — operational, nhẹ, vào thường xuyên. Trả lời: *"Việc gì cần xử lý ngay?"*
2. **Statistics** — analytical, nặng hơn. Trả lời: *"Dữ liệu nói gì?"*

Dashboard **không** phải trang analytics SEO. Không đưa lại domain-wide SEO score, phân bổ điểm, ranking domain, keyword analytics sâu, hay chart lịch sử nặng vào Dashboard. Reporting nặng thuộc **Statistics** (`/seo/statistics`).

**Landing Dashboard = tổng quan account-wide.** Không hiện Global domain selectbox trên trang này; data luôn aggregate theo accessible sites (không phụ thuộc sticky domain filter).

Visual CSS ổn định: `seo/resources/css/operational-landing-dashboard.css` (Vite entry), class prefix `.ops-landing*`. Không dùng inline layout CSS.

## Design Principles

- Clean, professional, operational, calm, structured, fast.
- Nội dung hữu dụng ngay trong viewport đầu; không vanity metrics.
- Data density được phép nếu không ồn về mặt thị giác.
- Prefer tables + progress bars cho hành động; tránh chart trang trí.
- Dashboard là launch point vào module sẵn có — không nhân đôi workflow.

## Visual Language

- Nền trang: light neutral.
- Card: nền trắng, border 1px nhẹ, radius trung bình, shadow rất nhẹ hoặc không.
- Spacing rộng vừa phải; hierarchy rõ.
- Color restrained, semantic.
- Không hero lớn, không gradient trang trí, không animation thừa, không icon/badge tràn lan.
- Dùng visual language SEO Ops hiện có (Filament + Tailwind), không invent design system mới.

**Phạm vi visual từ mockup:** Chỉ áp dụng cho **content canvas** (title, KPI, card, table, badge, progress, alert). Shell (header, sidebar, nav, logo, topbar, Help, profile, language) giữ nguyên product hiện tại.

## Typography

| Element | Intent |
|---------|--------|
| Page title | ~28–32px, semibold/bold, dark neutral |
| Subtitle | nhỏ hơn, muted, tối đa 1 câu |
| Card title | ~15–18px, semibold |
| Primary KPI number | dominant trong card, contrast mạnh, không oversized |
| Secondary labels | muted gray, compact |
| Table headers | quieter hơn cell values; compact scan-friendly |

## Spacing

Rhythm gần đúng:

- page horizontal padding: 24–32px
- section gaps: 16–24px
- card padding: 16–20px
- card gap: 12–16px
- title → subtitle: nhỏ
- subtitle → content: ~1 section unit

Reuse Tailwind / token hiện có; tránh spacing arbitrary mỗi component.

## Cards

- Một card = một mục đích rõ.
- White + subtle border + moderate radius.
- Không nested-card overload.
- Card nhóm thông tin, không trang trí.

## Color Semantics

| Color | Meaning |
|-------|---------|
| Blue / primary | informational, neutral progress, links, primary actions |
| Green | completed, reviewed/đã xử lý, healthy, success |
| Orange / amber | pending, cần chú ý, chưa review |
| Red | lỗi thật / failed / urgent only |
| Purple | secondary process (vd. AI running, published) — dùng tiết kiệm |

Trend ↑/↓ **không** tự động đỏ/xanh theo hướng số. State trước, trend sau. Ví dụ “chưa review tăng” không bắt buộc mã hóa đỏ nếu không có semantic rõ.

## Icons

- Line icons, cùng set app (Heroicons/Lucide theo chỗ đang dùng).
- KPI: icon nhỏ trong tinted container được phép.
- Text là primary; icon không bắt buộc để hiểu UI.
- Table row thường không cần icon trừ khi biểu đạt state/action.

## Tables

- Prefer table khi user cần act trên từng item.
- Header sạch, divider nhẹ, không heavy grid.
- Status badge compact; title clickable → route hiện có.
- Preview truncated → “Xem tất cả” rõ ràng.
- Bound rows (5–10), không load hundreds.

## Status Badges

| State | Style |
|-------|-------|
| Pending / Chưa review | soft amber bg + amber text |
| Reviewed / Completed | soft green bg + green text |
| Scheduled | soft blue |
| Waiting publish | soft amber/yellow |
| Error | soft red bg + red text |

Không dùng màu badge bão hòa.

## Progress Visualization

- Horizontal progress bars cho tiến độ tháng / pending vs done / published vs total.
- **Không** pie/donut cho operational workflow status trên Dashboard.

## Dashboard vs Statistics

| | Dashboard | Statistics |
|---|-----------|-----------|
| Job | What needs attention now | What data tells me |
| Load | Cheap counts + bounded lists | Heavier aggregates / charts |
| Owns | Review workload, publish queue preview, attention, recent activity, monthly ops progress | Domain/user analytics, SEO scores, historical charts, optimization counts, index/sync analytical trends |
| Filters | Minimal / none on landing | Tabs + filters same row |

## Role-aware Dashboard

Không build một Dashboard khổng lồ rồi CSS-hide. Resolve variant từ `seo_rule` / `SeoAccessControl::effectiveRole()`, load **chỉ** data của variant đó.

### Manager / Planner

Reference: `dashboard-manager-planner.png` (content area only).

Mental model: *"What needs attention across the operation?"*

**Layout (content):**

1. Title + subtitle (operational overview) — optional “today” date text nếu có sẵn, không thêm shell control mới.
2. KPI row (5 hoặc subset truthful):
   - Cần review / Needs review
   - Đã duyệt / Approved (hoặc equivalent workflow “đã xử lý” theo domain thật)
   - Lên lịch hôm nay / Scheduled today
   - Lỗi publish / Publish errors
   - AI đang chạy / AI running
3. Two columns:
   - Việc cần chú ý (bounded attention list)
   - Hoạt động hôm nay (bounded activity timeline) — chỉ nếu có nguồn event thật; không bịa feed
4. Two columns:
   - Tiến độ nội dung tháng (progress bars)
   - Hàng chờ xuất bản (bounded queue preview)
5. Optional info banner: deep SEO metrics → Statistics (link chỉ khi route Statistics tồn tại; nếu chưa có thì banner text-only hoặc ẩn CTA)

**Không show:** SEO score distribution, domain ranking, per-domain score analytics, keyword deep analytics, indexing deep analysis, large historical charts.

### Content Manager

Reference: `dashboard-content-manager.png` (content area only).

Mental model: *"What articles do I still need to process?"*

Primary axis: **Chưa xử lý vs Đã xử lý** (product labels map tới Needs Review / In Review reporting — xem Implementation Constraints).

**Layout:**

1. Title + CM-focused subtitle
2. KPI ×4:
   - Chưa review
   - Đã review
   - Cần xử lý hôm nay (chỉ nếu có concept đáng tin; không thì bỏ hoặc thay KPI đơn giản hơn)
   - Đã xử lý hôm nay
3. Tables ×2:
   - Bài viết chờ review (title, project, updated, status)
   - Bài viết đã review gần đây (title, reviewer, time, status)
4. Tiến độ xử lý tháng (progress bars: tổng / chưa / đã / đã xuất bản — theo dữ liệu thật trong scope CM)
5. Không expose AI internals, sync infra, domain SEO scores, team analytics.

## Statistics Layout Contract

Reference: `statistics.png` — **implemented** at `/seo/statistics` (manager/planner).

```
[Page heading + subtitle]

[ Tabs (Theo tên miền | Theo người dùng)     ][ filters domain | time | compare | export ]
```

Filters nằm **cùng hàng với tabs**, căn phải — **không** cạnh page heading.

Statistics owns domain/user analytics, SEO score metrics, historical charts, comparisons.
Export CTA hiện disabled (chưa có export subsystem riêng).

CSS: `seo/resources/css/ops-statistics.css` (Vite), prefix `.ops-statistics*`.
Read models: `DomainStatisticsReadModel` (seo), `UserStatisticsReadModel` (content-projects).
Không reuse `OperationalLandingDashboardReadModel`.

## Responsive Rules

Desktop primary.

- KPI: 4–5 cols desktop → 2 md → 1 sm
- Dual tables / dual panels: 2 cols desktop → stack hẹp
- Tables: follow existing responsive table convention; tránh cột quá hẹp

## Loading / Empty States

- Reuse `list-table-loading-shell` / shared SEO Ops loading patterns — không invent spinner system mới.
- Empty states compact, plain text:
  - “Không có bài viết đang chờ review.”
  - “Chưa có bài viết được review gần đây.”
  - Tương tự cho attention / queue / activity.
- Không empty-state illustration lớn.

## Interaction Rules

| UI | Destination |
|----|-------------|
| Article / task title | Existing article editor or project ops item |
| Xem tất cả | Existing filtered list (Needs Review / queue / articles) |
| Attention item | Existing relevant screen |
| Queue row | Publishing queue / project ops |
| KPI card | Optional deep-link to matching filter nếu URL đã tồn tại |

Không duplicate module functionality trong Dashboard.

## Performance Rules

`/seo` Dashboard phải rẻ:

- Small COUNT queries
- Bounded recent lists (`LIMIT`)
- Indexed status / timestamp filters
- Prefer DB-level filter; không fetch all rồi aggregate PHP
- Không scan all domains for SEO scoring
- Không score distribution / writer analytics charts trên landing
- Không chạy query thuộc Statistics

Role-aware load: content_manager **không** execute dataset manager-only.

## Implementation Constraints

1. **Shell authoritative** — không redesign header/sidebar/nav.
2. **Map mockup → domain thật**; không invent fake KPI.
3. Content Manager review semantics (canonical hiện tại):
   - **Chưa review / Cần biên tập** ≈ `ContentProjectRecentlyCompletedDefinition` (Needs Review / `recently_completed`) — AI xong, chưa CM Save stamp (và unread/re-run rules theo definition).
   - **Đã review / Đã biên tập** ≈ `content_manager_reviewed_at` / In Review reporting definition.
   - Không nhầm với planner `articles.review_status = approved`.
4. Manager/planner operational KPIs reuse Ops/Queue sources:
   - AI running / waiting / failed → `ContentProjectOpsDashboardService` / task+run statuses
   - Publish errors / queue → `ContentProjectQueueHealthService` / publish queue status
   - Scheduled today → `scheduled_publish_at` (ngày hôm nay) nếu query đã có pattern
5. Scope CM lists theo ownership đã có (`ArticleResource` / project assignment), không dùng unscoped domain health.
6. Architecture: `DashboardReadModel` (hoặc service tách `managerPlanner` / `contentManager`) — không `loadEverything()` rồi branch Blade.
7. Tests bắt buộc theo role visibility, empty states, bounded queries, permissions.

## Reference Images

The following images are references for the page **CONTENT AREA** only.
Ignore all header, sidebar, navigation, logo, topbar and global shell details.

- `dashboard-manager-planner.png`
- `dashboard-content-manager.png`
- `statistics.png`
