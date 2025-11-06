# Report Fields & Section Features

This spec describes the live implementation of the Report editor (WP admin), the data it stores, and how the public report template renders each module. Use it as the authoritative reference when extending the report experience.

---

## 1. Global Meta & Controls

| UI control | Meta key | Notes |
|---|---|---|
| Report Type | `_aiseo_report_type` | `general`, `technical`, `per_page` (per-page is treated as “Content Audit” in docs/spec). |
| Project | `_aiseo_project_slug` | Populated from `Storage::baseDir()` folders. |
| Page URL | `_aiseo_page` | Only shown for `per_page`. |
| Runs | `_aiseo_runs` | JSON array of run IDs; sanitized on save. |
| Executive Summary | `_aiseo_summary` | WYSIWYG/TinyMCE field. |
| Show Executive Summary | `_aiseo_summary_visible` | `'1'` (default) or `'0'`. Controls rendering on the front end. |
| Top Actions | `_aiseo_top_actions` | JSON array of short action strings. |
| Meta Recommendations | `_aiseo_meta_recos` | JSON array of objects (`url`, `title`, `meta_description`). |
| Technical Findings | `_aiseo_tech_findings` | Freeform notes. |
| Report Snapshot | `_aiseo_snapshot` | JSON payload from `DataLoader::forReport()`. |
| Modular Sections | `_aiseo_sections` | JSON array of section objects (see §3). |

The “Generate AI Summary” button in the metabox still calls `Gemini::summarizeReport()` and updates `_aiseo_summary`, `_aiseo_top_actions`, `_aiseo_meta_recos`, `_aiseo_tech_findings`, `_aiseo_snapshot`, and leaves `_aiseo_summary_visible` untouched.

---

## 2. Section Editor (ReportSectionsUI)

- Fixed list of sections defined in `AISEO\Helpers\Sections::registry()`.
- Each section row renders:
  - **Show section** checkbox (persisted as `visible` in the section object).
  - Contextual metrics table (read-only) sourced from `ReportMetrics::build()`.
  - TinyMCE editor for the narrative body (`body`).
  - “AI” button that hits `Gemini::summarizeSection()` and replaces the editor content (and optional `reco_list` for Recommendations).
  - `Recommendations` section includes an additional textarea to capture bullet items (`reco_list`).
- “Generate AI for All Sections” loops through every section and triggers the AJAX helper sequentially.

The editor no longer supports ad-hoc add/remove; order is fixed by the registry and persisted via the `order` field.

---

## 3. Section Object Schema (`_aiseo_sections`)

```json
{
  "id": "overview_ab12cd",
  "type": "overview",
  "title": "Overview",
  "body": "<p>Editable narrative…</p>",
  "reco_list": ["Bullet 1", "Bullet 2"],
  "visible": 1,
  "order": 10
}
```

- `visible` is stored as `1` or `0`. Defaults to visible.
- `order` controls render order (multiple of 10).
- `type` is one of the registered keys. Legacy keys remain readable but are hidden from defaults.

---

## 4. Section Catalogue

| Type key | Default label | Enabled report types | Notes |
|---|---|---|---|
| `overview` | Overview | general, technical, per_page | Metrics table varies per report type. |
| `performance_summary` | Performance Summary | general, technical, per_page | Shows GA/GSC/Crawler highlights. |
| `technical_seo_issues` | Technical SEO Issues | general, technical, per_page | Table of issue counts + sample URLs. |
| `onpage_seo_content` | On-Page SEO & Content | general, technical, per_page | Guides for titles, meta, H1, content depth, imagery. |
| `keyword_analysis` | Keyword Analysis | general, technical, per_page | Summaries from GSC data (with graceful fallbacks). |
| `backlink_profile` | Backlink Profile | general, technical, per_page | Displays backlink snapshot if data exists. |
| `recommendations` | Recommendations | general, technical, per_page | Uses `reco_list` bullets + editor body. |

Legacy keys (`performance_issues`, `technical_seo`, `onpage_meta_heading`, `onpage_content_image`) are still recognized for existing posts but are no longer added by default.

---

## 5. Metrics Reference

`AISEO\Helpers\ReportMetrics::build($type, $snapshot)` compiles the data used for admin previews and the public template tables.

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

1. Loads `_aiseo_snapshot` (or recomputes via `DataLoader`).
2. Builds section metrics via `ReportMetrics::build()`.
3. Filters out sections where `visible` is falsy.
4. Renders each section in order:
   - Heading (defaults to registry label if title empty).
   - Metrics table / notes (if any).
   - Narrative body (`wpautop`-formatted).
   - Recommendations list for `recommendations` section.
5. Executive Summary respects `_aiseo_summary_visible`.

All tables reuse the `table.metrics` styling; helper classes `.metrics-empty` and `.metrics-note` provide fallback messaging.

---

## 7. AI Hooks

- **Section-level AI**: `wp_ajax_aiseo_sections_generate` calls `Gemini::summarizeSection($type, $data, $sectionId)` and updates both admin editor (TinyMCE) and recommendation textarea.
- **Label mapping**: `Gemini::labelForSection()` now understands the new section prefixes while still handling legacy IDs.

---

## 8. Migration / Compatibility Notes

- Existing reports without `visible` default to showing all sections.
- Legacy section types continue to display (labeled as their original names) but are excluded from defaults for new reports.
- `_aiseo_summary_visible` defaults to `1` to preserve current behaviour.
- If analytics/backlink data is absent, tables render placeholders instead of breaking.

---

**Maintainers:** AI SEO Tools / Blackbird Media  
**Last updated:** 2025-02-06 (Codex implementation sync)
