# AI SEO Tools — Milestone Snapshot (5 Nov 2025)

## Project Brief

Build a WordPress-based SEO auditing service that:

- Seeds crawl queues through REST endpoints or wp-admin buttons.
- Processes pages, extracts metadata (titles, descriptions, headings, images, OG tags, schema), and stores results in JSON.
- Generates audit summaries and human-readable reports.
- Preserves historical snapshots for future comparisons.
- Supports manual and scheduled crawl cadences (manual/weekly/monthly) with a global scheduler toggle.

The stack is **WordPress Bedrock** with a custom theme `ai-seo-tool`. All business logic lives inside the theme (`web/app/themes/ai-seo-tool`).

## Current Architecture

### Theme structure

```
web/app/themes/ai-seo-tool/
├── app/
│   ├── Admin/Dashboard.php      # wp-admin dashboard (AI SEO menu)
│   ├── Audit/Runner.php         # Aggregates issues & summaries
│   ├── Crawl/
│   │   ├── Queue.php            # Queue management helpers
│   │   ├── Worker.php           # Spatie crawler integration
│   │   ├── Observer.php         # Spatie callbacks → Worker handlers
│   │   └── Profile.php          # Domain-restricted crawl profile
│   ├── Cron/Scheduler.php       # WP-Cron orchestration
│   ├── Helpers/{Storage,Http}.php
│   ├── PostTypes/Project.php    # CPT + scheduling meta
│   ├── Report/Builder.php       # Builds report.json (summary + top issues)
│   ├── Rest/Routes.php          # `/start-crawl`, `/crawl-step`, etc.
│   └── AI/Gemini.php            # Placeholder for future AI summaries
├── templates/report.php         # Front-end report viewer
├── storage/projects/            # Per-project data (queue/pages/report/history)
└── composer.json                # Theme-level dependencies
```

### Key dependencies

- `spatie/crawler` (`symfony/dom-crawler`, `symfony/css-selector`) for discovery.
- `guzzlehttp/guzzle` for HTTP requests when needed.
- WordPress REST API and WP-Cron for orchestration.
- Bedrock autoloader + PSR-4 namespace `AISEO\`.

### Data layout

```
storage/projects/{slug}/
├── queue/    # *.todo / *.done files
├── pages/    # {md5(url)}.json per crawled page
├── audit.json
├── report.json
└── history/
    └── {YYYYmmdd-HHMMSS}/
        ├── audit.json
        ├── report.json
        └── summary.json
```

`summary.json` captures run timestamp, page counts, status buckets, and top issues for quick comparisons.

## Current Workflow

### 1. Seeding crawls (Manual & Scheduled)

- **Manual**: wp-admin → **AI SEO** → *Run Crawl Now*. This mirrors `POST /start-crawl`, simply seeding the queue from the project’s Primary URL. If the project lacks a base URL, the action aborts with an error notice.
- **REST**: `POST /wp-json/ai-seo-tool/v1/start-crawl?project={slug}&key={token}` accepts optional `urls[]`.
- **Scheduler**: Projects set to *Weekly* or *Monthly* automatically seed when the cadence threshold is met.

### 2. Processing queue (`/crawl-step`)

- **WP-Cron**: Hourly hook `aiseo_cron_tick`:
  1. Ensures directory structure exists (`Storage::ensureProject`).
  2. Seeds weekly/monthly projects when due.
  3. If any TODO files exist, runs `Scheduler::processQueue($slug)` (max 50 steps per tick).
  4. When a queue empties, runs `AuditRunner`, `ReportBuilder`, snapshots to `history/`, and updates last-run meta.
- **Manual REST**: `GET /wp-json/ai-seo-tool/v1/crawl-step?project=...` processes a single step. REST endpoints also trigger audit/report automatically when the queue drains.

### 3. Page extraction pipeline

For each processed URL:

1. Spatie crawler fetches the page (single depth per queue step).
2. `Worker` extracts:
   - Status code, headers, content length/type.
   - Title, meta description, meta robots, canonical.
   - Heading hierarchy (H1–H6).
   - Images (`src`, `alt`), internal & external link lists.
   - JSON-LD structured data (parsed when valid JSON).
   - Open Graph meta tags.
3. Internal links from the same host are normalized and enqueued (dedupe vs. queue, done, saved pages).

### 4. Auditing & reporting

- `AuditRunner` evaluates each page, adding issues for:
  - Status buckets (3xx/4xx/5xx/missing).
  - Title/meta length or presence.
  - Missing canonical, multiple/missing H1, large payloads, missing image alt.
  - Missing OG tags or structured data.
- Summaries include total pages, status distribution, per-issue counts.
- `ReportBuilder` merges audit summary + crawl stats, surfaces top 10 issues, and writes `report.json`.
- `templates/report.php` renders the JSON data for front-end viewing (`/ai-seo-report/{slug}`).

### 5. Admin dashboard (wp-admin → AI SEO)

- Lists projects with primary URL, schedule, page counts, last crawl (relative timestamp), and top issues.
- Actions: View report, Run Crawl Now (seed), REST Status link, Scheduler enable/disable toggle.
- Scheduler status persists via `aiseo_scheduler_enabled` option and `.env` flag `AISEO_DISABLE_CRON`.

### 6. Environment configuration

`.env` (root) / `.env.example` include:

```
AISEO_SECURE_TOKEN=...                 # Shared secret for REST endpoints
AISEO_STORAGE_DIR=${WP_CONTENT_DIR}/themes/ai-seo-tool/storage/projects
AISEO_HTTP_VERIFY_TLS=false            # Optional (skip TLS verify for local/self-signed)
AISEO_DISABLE_CRON=true                # Optional global cron kill-switch
```

Bedrock `.env` still handles WP_HOME, DB credentials, salts, etc.

## REST Endpoints (all require `key={AISEO_SECURE_TOKEN}`)

- `POST /ai-seo-tool/v1/start-crawl` — seeds queue (`urls[]` optional).
- `GET /ai-seo-tool/v1/crawl-step` — processes one queue item.
- `POST /ai-seo-tool/v1/audit` — force audit rebuild.
- `POST /ai-seo-tool/v1/report` — force report rebuild.
- `GET /ai-seo-tool/v1/status` — queue counts, page totals, base URL.

## Scheduler Controls & Behavior

- Enabled by default; toggle via wp-admin or `.env`.
- Uses `wp_schedule_event(... 'hourly', ...)`.
- Manual runs only seed; actual crawling happens when cron next fires.
- `processQueue` batches up to 50 steps per tick. Remaining items are processed on subsequent ticks.
- Upon queue completion:
  - Audit/report rebuilt.
  - Snapshot saved to `history/{timestamp}`.
  - Project’s `_aiseo_project_last_run` meta updated.

## Current Situation / Next Opportunities

- **Implemented**:
  - End-to-end crawl/audit/report pipeline with history snapshots.
  - REST API for queue operations.
  - wp-admin dashboard + scheduler toggle.
  - Per-project scheduling metadata (manual/weekly/monthly).
  - HTML report template (Bootstrap) summarizing key metrics + issues.

- **Known gaps / backlog**:
  - Compare history snapshots over time (visual diffs, trend charts).
  - Notification or alerting when new issues appear.
  - Bulk CLI tooling (`wp ai-seo ...`) for manual queue draining.
  - AI summaries (placeholder `AI/Gemini.php`).
  - Export CSV/PDF (dompdf already in root composer).

This document reflects the codebase as of **5 Nov 2025**. Use it as the baseline when syncing with additional agents or planning subsequent milestones.
