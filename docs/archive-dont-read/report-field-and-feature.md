# Report Fields & Section Features

This spec describes the live implementation of the Report editor (WP admin), the data it stores, and how the public report template renders each module. Use it as the authoritative reference when extending the report experience.

---

## 1. Global Meta & Controls

| UI control | Meta key | Notes |
|---|---|---|
| Report Type | `_BBSEO_report_type` | `general`, `technical`, `per_page` (per-page is treated as “Content Audit” in docs/spec). |
| Project | `_BBSEO_project_slug` | Populated from `Storage::baseDir()` folders. |
| Page URL | `_BBSEO_page` | Only shown for `per_page`. |
| Runs | `_BBSEO_runs` | JSON array of run IDs; sanitized on save. |
| Executive Summary | `_BBSEO_summary` | Synced from the `executive_summary` section. |
| Top Actions | `_BBSEO_top_actions` | Synced from the `top_actions` section (`reco_list`). |
| Meta Recommendations | `_BBSEO_meta_recos` | Synced from the `meta_recommendations` section (`meta_list`). |
| Technical Findings | `_BBSEO_tech_findings` | Synced from the `technical_findings` section. |
| Report Snapshot | `_BBSEO_snapshot` | JSON payload from `DataLoader::forReport()`. |
| Modular Sections | `_BBSEO_sections` | JSON array of section objects (see §3). |

The legacy “Generate AI Summary” control is retired; each section now has its own AI button. When a section is saved, its content is mirrored into the backing post meta listed above so existing consumers keep working.

---

## 2. Section Editor (ReportSectionsUI)

- Fixed list of sections defined in `BBSEO\Helpers\Sections::registry()`.
- Each section row renders:
  - **Show section** checkbox (persisted as `visible` in the section object).
  - Manual order input (`order`, 0–15) that sets the render priority.
  - Contextual metrics table (read-only) sourced from `ReportMetrics::build()`.
  - TinyMCE editor for the narrative body (`body`).
  - “AI” button that hits `Gemini::summarizeSection()` and replaces the editor content (and optional lists).
  - `top_actions` and `recommendations` expose a textarea for one-action-per-line (`reco_list`).
  - `meta_recommendations` exposes a JSON textarea (`meta_list`) for URL/title/description rows.
- “Generate AI for All Sections” loops through every section and triggers the AJAX helper sequentially.
- Section-level visibility replaces the legacy `_BBSEO_summary_visible` toggle.

The editor no longer supports ad-hoc add/remove; order is fixed by the registry and persisted via the `order` field.

---

## 3. Section Object Schema (`_BBSEO_sections`)

```json
{
  "id": "overview_ab12cd",
  "type": "overview",
  "title": "Overview",
  "body": "<p>Editable narrative…</p>",
  "reco_list": ["Bullet 1", "Bullet 2"],
  "meta_list": [],
  "visible": 1,
  "order": 10
}
```

- `visible` is stored as `1` or `0`. Defaults to visible.
- `order` controls render order (user-entered 0–15 priority in the editor).
- `type` is one of the registered keys. Legacy keys remain readable but are hidden from defaults.
- `meta_list` is used by the `meta_recommendations` section for structured rows (`url`, `title`, `meta_description`).

---

## 4. Section Catalogue

| Type key | Default label | Enabled report types | Notes |
|---|---|---|---|
| `executive_summary` | Executive Summary | general, technical, per_page | Narrative lead. Controls the summary block on the report. |
| `top_actions` | Top Actions | general, technical, per_page | One action per line (`reco_list`); rendered as the high-priority list. |
| `overview` | Overview | general, technical, per_page | Metrics table varies per report type. |
| `performance_summary` | Performance Summary | general, technical, per_page | Shows GA/GSC/Crawler highlights. |
| `technical_seo_issues` | Technical SEO Issues | general, technical, per_page | Table of issue counts + sample URLs. |
| `onpage_seo_content` | On-Page SEO & Content | general, technical, per_page | Guides for titles, meta, H1, content depth, imagery. |
| `keyword_analysis` | Keyword Analysis | general, technical, per_page | Summaries from GSC data (with graceful fallbacks). |
| `backlink_profile` | Backlink Profile | general, technical, per_page | Displays backlink snapshot if data exists. |
| `meta_recommendations` | Meta Recommendations | general, technical, per_page | Stores structured URL/title/description rows (`meta_list`). |
| `technical_findings` | Technical Findings | general, technical, per_page | Freeform notes section for deeper technical commentary. |
| `recommendations` | Recommendations | general, technical, per_page | Uses `reco_list` bullets + editor body for follow-up tasks. |

Legacy keys (`performance_issues`, `technical_seo`, `onpage_meta_heading`, `onpage_content_image`) are still recognized for existing posts but are no longer added by default.

---

## 5. Metrics Reference

`BBSEO\Helpers\ReportMetrics::build($type, $snapshot)` compiles the data used for admin previews and the public template tables.
Narrative-only sections (`executive_summary`, `top_actions`, `meta_recommendations`, `technical_findings`) intentionally return empty metric sets and rely on their saved content for rendering.

### 5.1 Overview Metrics

| Report Type | Metrics surfaced |
|---|---|
| General SEO Audit | Total pages crawled, indexed pages (2xx), average word count, broken links (4xx), missing titles, missing meta descriptions, long titles (>65 chars), pages with <300 words. |
| Technical SEO Audit | Total pages, indexed pages, broken links, missing titles, missing meta descriptions, redirect chains, mixed-content pages. |
| Content Audit (`per_page`) | Total pages, indexed pages, average word count, thin content (<300 words), duplicate content issues, missing titles, missing meta descriptions. |

### 5.2 Performance Summary

| Report Type | Sources / Metrics |
|---|---|
| General | GA: Top pageviews; GSC: Best performing keyword; Crawler: Largest page. |
| Technical | GSC: Top crawl errors; Crawler: Slowest page; Crawler: Most redirect hops. |
| Content (`per_page`) | GA: Top exit page; GSC: Lowest CTR keyword; Crawler: Shortest content page. |

Missing analytics data is surfaced with friendly “connect data source” prompts.

### 5.3 Technical SEO Issues

- **General / Technical**: Broken links, missing canonical, redirect chains, missing H1, HTTPS/mixed-content issues.
- **Content**: Duplicate content, thin content, missing titles, missing meta, images missing ALT.

Each row includes a count and the first sample URL (when available).

### 5.4 On-Page SEO & Content

Fixed guidance table populated with live counts:

- Title (missing/length issues)
- Meta description (missing)
- Headings (missing H1)
- Word count (pages under 300 words)
- Images (missing ALT text)

### 5.5 Keyword Analysis

Rows summarize:

- Top keywords by impressions
- Top keywords by clicks
- Keywords with low CTR
- High-impression / low-position opportunities

When Search Console data is missing, placeholders prompt the user to connect a source.

### 5.6 Backlink Profile

Displays referring domains, total backlinks, new/lost links, average toxic score. Notes highlight when backlink data is missing.

---

## 6. Front-End Rendering (`templates/report.php`)

1. Loads `_BBSEO_snapshot` (or recomputes via `DataLoader`).
2. Builds section metrics via `ReportMetrics::build()`.
3. Filters out sections where `visible` is falsy.
4. Renders each section in order:
   - Heading (defaults to registry label if title empty).
   - Metrics table / notes (if any).
   - Narrative body (`wpautop`-formatted).
   - Recommendations list for `recommendations` section.
5. Executive Summary visibility is driven by the section’s `visible` flag.

All tables reuse the `table.metrics` styling; helper classes `.metrics-empty` and `.metrics-note` provide fallback messaging.

---

## 7. AI Hooks

- **Section-level AI**: `wp_ajax_BBSEO_sections_generate` calls `Gemini::summarizeSection($type, $data, $sectionId)` and updates both admin editor (TinyMCE) and recommendation textarea.
- **Label mapping**: `Gemini::labelForSection()` now understands the new section prefixes while still handling legacy IDs.

---

## 8. Migration / Compatibility Notes

- Existing reports without `visible` default to showing all sections.
- Legacy section types continue to display (labeled as their original names) but are excluded from defaults for new reports.
- Existing reports without explicit `visible` flags default to showing the new sections.
- If analytics/backlink data is absent, tables render placeholders instead of breaking.

---

**Maintainers:** Blackbird SEO Tools / Blackbird Media  
**Last updated:** 2025-02-06 (Codex implementation sync)
