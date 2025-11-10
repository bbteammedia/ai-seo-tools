# Blackbird SEO Tools – Report Rendering & Data Binding (Spec)

> Goal: render database-saved Report CPT into the clean report layout (screenshot), with modular sections (add/remove/reorder), AI-prefill, and data pulled from run summaries and optional GA/GSC/backlink imports.

---

## 1) Entities & Keys

**Post type:** `BBSEO_report`

**Base meta (existing):**
- `_bbseo_report_type` → `general | per_page | technical`
- `_bbseo_project_slug` → e.g. `"blackbird"`
- `_bbseo_runs` → `["2025-11-06_13:12_1B7C", ...]` (JSON; use latest if empty)
- `_bbseo_page` → URL (only when `per_page`)
- `_bbseo_summary` → overall executive summary (string)
- `_bbseo_top_actions` → JSON array of strings
- `_bbseo_meta_recos` → JSON array of objects
- `_bbseo_tech_findings` → string
- `_bbseo_snapshot` → JSON snapshot used for AI
- `_bbseo_sections` → JSON array (modular sections; see below)

**Run storage (filesystem):**
- `storage/projects/{project}/runs/{run}/summary.json`
- `storage/projects/{project}/runs/{run}/report.json`
- `storage/projects/{project}/runs/{run}/audit.json`
- `storage/projects/{project}/timeseries.json`
- `storage/projects/{project}/analytics/ga-timeseries.json` (optional/if exists)
- `storage/projects/{project}/analytics/gsc-timeseries.json` (optional/if exists)
- `storage/projects/{project}/runs/{run}/analytics/ga.json` (optional/if exists)
- `storage/projects/{project}/runs/{run}/analytics/gsc.json` (optional/if exists)
- `storage/projects/{project}/runs/{run}/analytics/gsc-details.json` (optional/if exists)
- `storage/projects/{project}/runs/{run}/backlinks/provider.json` (optional, future)

---

## 2) Modular Sections (DB shape)

Each section object inside `_bbseo_sections`:

```json
{
  "id": "overview_ab12cd",
  "type": "overview",
  "title": "Overview",
  "body": "Editable narrative…",
  "reco_list": ["Bullet 1","Bullet 2"],
  "order": 10,
  "show": true
}
```

### Section registry (canonical keys)
- `overview`
- `performance_issues`
- `technical_seo`
- `onpage_meta_heading`
- `onpage_content_image`
- (extendable) `keyword_analysis`, `backlink_profile`, `recommendations`

> Prefill based on report type (General / Per Page / Technical). Admin can change order, hide/show, add/remove. Titles are editable per section.

---

## 3) Layout → Data Binding

### Header
- **Title:** Post title (e.g., “Blackbird”)
- **Subtitle:** fixed label, e.g., `Website General SEO Audit` (or dynamic by type)
- **Generated date:** `date_i18n( get_option('date_format') )`
- **Pills:**
  - **Project:** `_bbseo_project_slug`
  - **Runs:** if `_bbseo_runs` empty → `Latest run`, else `N runs`
  - **Total Pages:** sum of `summary.pages` across selected runs (or latest)
  - **Total Issues:** sum of `summary.issues.total` across selected runs (or latest)

### Executive Summary
- From `_bbseo_summary`. If empty, show muted “No summary yet.”

### Key Metrics Snapshot (latest run)
- Total Pages Crawled → `summary.pages`
- Indexed Pages (2xx) → `summary.status.2xx`
- Broken Links (4xx) → `summary.status.4xx`
- Server Errors (5xx) → `summary.status.5xx`
- Average Word Count → average of `pages/*.json.word_count` (or `—` if unknown)
- Pages Missing Title → count where page `title` is empty
- Pages Missing Meta Description → count where page `meta_description` is empty

> If a metric isn’t available, render `—` and a light hint like “Crawler data required”.

### Top Actions
- `_bbseo_top_actions` (array). If empty, hide section.

### Detailed Sections (iterate ordered, `show == true`)

**A. Performance Summary**  
- 3 rows (Source | Metric | Top Value):
  - Google Analytics → Top Pageviews → from `analytics/ga.json` (else “Connect Google Analytics”)
  - Google Search Console → Best Performing Keyword → from `analytics/gsc.json` (else “Connect Google Search Console”)
  - Crawler → Largest Page → compute from `pages/*.json` by size or word_count (else “Crawler data required”)

**B. Technical SEO Issues** (Issue Type | Count | Example URL)
- Rows to compute or read from audit: Broken Links (4xx), Missing Canonical, Redirect Chains, Missing H1, HTTPS/Mixed.
- Count = occurrences; Example URL = first match or `—`.

**C. On-Page SEO & Content** (Element | Common Issue | Recommendation)
- Pull signals from your dataset to prefill common issues; default recommendations:
  - Title → Keep unique titles under ~55–60 chars.
  - Meta Description → 110–155 chars; intent-driven.
  - Headings → Single H1; semantic hierarchy.
  - Word Count → Expand thin content; add internal links.
  - Images → Descriptive ALT; compress large images.

**D. Keyword Analysis** (optional; requires GSC)
- Top keywords by impressions
- Top keywords by clicks
- Keywords with low CTR
- High impressions, low position

**E. Backlink Profile** (optional; requires provider)
- Referring Domains, Total Backlinks, New Links, Lost Links, Average Toxic Score

**F. Recommendations** (freeform)
- Can be its own section or derived from Top Actions + AI narrative.

### Footer
- “Prepared with Blackbird SEO Tools · {date}”

---

## 4) Rendering Template (PHP outline)

Create `web/app/themes/ai-seo-tool/templates/report-post.php` and load it for single `BBSEO_report`.

```php
<?php
use BBSEO\Helpers\Storage;
use BBSEO\Helpers\Sections;

$post_id = get_the_ID();
$project = get_post_meta($post_id, '_bbseo_project_slug', true);
$runs = json_decode(get_post_meta($post_id, '_bbseo_runs', true) ?: '[]', true);
if (!$runs) { $latest = Storage::getLatestRun($project); if ($latest) $runs = [$latest]; }

// Load summaries
$summaries = [];
$total_pages = 0; $total_issues = 0;
foreach ($runs as $run) {
  $sum = json_decode(@file_get_contents(Storage::runDir($project,$run).'/summary.json'), true) ?: [];
  $summaries[] = $sum;
  $total_pages += intval($sum['pages'] ?? 0);
  $total_issues += intval($sum['issues']['total'] ?? 0);
}
$latestSum = end($summaries) ?: [];

$exec = get_post_meta($post_id, '_bbseo_summary', true);
$actions = json_decode(get_post_meta($post_id, '_bbseo_top_actions', true) ?: '[]', true);
$sections = json_decode(get_post_meta($post_id, Sections::META_SECTIONS, true) ?: '[]', true);
usort($sections, fn($a,$b)=>intval($a['order']??0)<=>intval($b['order']??0));

function dash($v){ return ($v === 0 || $v === '0') ? '0' : ($v ? esc_html($v) : '—'); }
?>
<!-- Build the HTML using your card/table CSS. Respect $sections order and show flags. -->
```

> Keep styles minimal: rounded cards, muted dividers, small “pill” badges for header metrics.

---

## 5) Editor UX (to match screenshot)

Per section in `_bbseo_sections` edit UI:
- **Order** (number input; default 10, 20, 30…)
- **Show section** (checkbox; default true)
- **AI** per-section and **Generate AI for All** (fills `body` + `reco_list`)
- **Title** (text), **Body** (wp_editor or textarea), **Recommendations** (newline list → array)

Field names:
- `BBSEO_sections[i][title]`
- `BBSEO_sections[i][body]`
- `BBSEO_sections[i][reco_raw]` → parsed to `reco_list[]`
- `BBSEO_sections[i][order]`
- `BBSEO_sections[i][show]`

---

## 6) AI hooks (Gemini stubs)

Extend `Gemini` helper with a section-level generator that returns:

```php
['body' => 'short narrative...', 'reco_list' => ['bullet 1','bullet 2']]
```

Prompt skeleton:

```
System: You are an SEO analyst.
User: Project {project}. Runs={N}. 2xx={...} 4xx={...} 5xx={...} Missing titles={...} Missing metas={...} Avg words={...}.
Task: Write a concise {sectionLabel} paragraph (≤120 words) and 3–5 action bullets.
Output JSON: {"body":"...","reco_list":["...","..."]}
```

Cache the last AI output in `_bbseo_snapshot` or per-section if you want.

---

## 7) Data utilities

Add/extend helpers to compute for latest run:
- Missing title count, missing meta count
- Average word count
- Largest page (by bytes or words)
- First example URL per issue type

If heavy, store these aggregates back into `summary.json` during finalize.

---

## 8) Frontend & PDF

- Public view: `single-bbseo_report.php` includes `templates/report-post.php`.
- PDF export admin route: reuse same HTML and stream via dompdf.

---

## 9) Empty states & safety

- If GA/GSC/backlinks missing → show muted “Connect …” text.
- If a number unknown → render `—`.
- Never block render; degrade gracefully.

---

## 10) Acceptance Checklist

- [ ] Header shows Project, Runs, Total Pages, Total Issues (correct sums).
- [ ] Executive Summary binds to `_bbseo_summary`.
- [ ] Key Metrics Snapshot reads latest `summary.json`; unknown metrics show `—`.
- [ ] Top Actions displays bullets or hides when empty.
- [ ] Detailed Sections render in saved order; respect **Show** flag.
- [ ] Each section prints title, body, and bullets.
- [ ] PDF export reuses same template & styles.
- [ ] Section-level AI fills body + bullets; admin edits persist.
- [ ] Works for General, Per Page, and Technical types.
