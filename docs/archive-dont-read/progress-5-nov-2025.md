# Blackbird SEO Tools — Milestone Snapshot (5 Nov 2025)

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
│   ├── Admin/Dashboard.php      # wp-admin dashboard (manual run trigger + status)
│   ├── Audit/Runner.php         # Aggregates issues & summaries per run
│   ├── Crawl/
│   │   ├── Queue.php            # Run-scoped queue helpers
│   │   ├── Worker.php           # Spatie crawler integration
│   │   ├── Observer.php         # Spatie callbacks → Worker handlers
│   │   └── Profile.php          # Domain-restricted crawl profile
│   ├── Cron/Scheduler.php       # 1-minute WP-Cron drain + auto-run logic
│   ├── Helpers/{Storage,Http,RunId}.php
│   ├── PostTypes/Project.php    # CPT (still leveraged for base URLs)
│   ├── Report/Builder.php       # Builds run-scoped report.json
│   ├── Rest/Routes.php          # `/start-crawl`, `/crawl-step`, etc.
│   └── AI/Gemini.php            # Placeholder for future AI summaries
├── templates/report.php         # Front-end report viewer
├── storage/projects/            # Per-project runs, config, latest pointer
└── composer.json                # Theme-level dependencies
```

### Key dependencies

- `spatie/crawler` (`symfony/dom-crawler`, `symfony/css-selector`) for discovery.
- `guzzlehttp/guzzle` for HTTP requests when needed.
- WordPress REST API and WP-Cron for orchestration.
- Bedrock autoloader + PSR-4 namespace `BBSEO\`.

### Data layout

```
storage/projects/{slug}/
├── runs/
│   └── {run_id}/
│       ├── queue/       # *.todo / *.done per run
│       ├── pages/       # {md5(url)}.json per run
│       ├── audit.json
│       ├── report.json
│       ├── meta.json    # started_at, completed_at, summary
├── config.json           # Scheduler configuration (enabled, frequency, seed_urls)
├── latest_run.txt        # Pointer to most recent run id
└── history/              # Optional legacy backups
```

`meta.json` records lifecycle timestamps while `report.json`/`audit.json` capture run-level results. `latest_run.txt` makes it easy to default to the most recent run.

## Current Workflow

### 1. Starting a run

- **Manual**: wp-admin → **Blackbird SEO** → *Run Crawl Now*. Generates a new `run_id`, seeds `runs/{run}/queue` with the configured URLs, and updates `latest_run.txt`.
- **REST**: `POST /wp-json/ai-seo-tool/v1/start-crawl?project={slug}&key={token}` optionally accepts `urls[]` or a pre-supplied `run_id`; the response includes `{ run_id, queued }`.
- **Scheduler**: Reads per-project `config.json` (`frequency`, `seed_urls`). When the cadence threshold is met, `Queue::init` is invoked automatically to spawn a new run folder.

### 2. Processing queue (`/crawl-step`)

- **WP-Cron**: Custom schedule `every_minute` triggers `BBSEO_minutely_drain`.
  1. Enumerates all projects (config-driven and manual-only) under `storage/projects/`.
  2. Seeds a fresh run when `config.json` frequency rules demand it.
  3. Drains up to `BBSEO_STEPS_PER_TICK` items per tick via `Worker::process($project, $runId)`.
  4. Once a run’s queue empties, `AuditRunner::run` + `ReportBuilder::build` generate results and update `meta.json` with completion data.
- **Manual REST**: `GET /wp-json/ai-seo-tool/v1/crawl-step?project=...&run=...` processes a single item and automatically builds audit/report when the queue empties.

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
- `templates/report.php` renders the JSON data for front-end viewing (`/report/{slug}`).

### 5. Admin dashboard (wp-admin → Blackbird SEO)

- Lists projects with primary URL, latest `run_id`, queue counts, and quick actions (view report / seed new run).
- Shows notices when manual runs succeed or when configuration is missing.
- Reads `config.json` for seed URLs; global cron disable still controlled via `.env` (`BBSEO_DISABLE_CRON=true`).

### 6. Environment configuration

`.env` (root) / `.env.example` include:

```
BBSEO_SECURE_TOKEN=...                 # Shared secret for REST endpoints
BBSEO_STORAGE_DIR=${WP_CONTENT_DIR}/themes/ai-seo-tool/storage/projects
BBSEO_HTTP_VERIFY_TLS=false            # Optional (skip TLS verify for local/self-signed)
BBSEO_DISABLE_CRON=true                # Optional global cron kill-switch
BBSEO_STEPS_PER_TICK=50               # Optional limit for minutely drain
```

Bedrock `.env` still handles WP_HOME, DB credentials, salts, etc.

## REST Endpoints (all require `key={BBSEO_SECURE_TOKEN}`)

- `POST /ai-seo-tool/v1/start-crawl` — creates a run directory and seeds `queue/`. Accepts optional `urls[]` and `run_id`; responds with `{ run_id, queued }`.
- `GET /ai-seo-tool/v1/crawl-step` — processes one item in `runs/{run}/queue`. When the queue empties, audit/report are generated automatically.
- `POST /ai-seo-tool/v1/audit` — rebuilds `runs/{run}/audit.json` (defaults to latest run).
- `POST /ai-seo-tool/v1/report` — rebuilds `runs/{run}/report.json`.
- `GET /ai-seo-tool/v1/status` — returns queue counts for the requested run (defaults to `latest_run.txt`).

## Scheduler Controls & Behavior

- Global disable via `.env` (`BBSEO_DISABLE_CRON=true`).
- Registers an `every_minute` interval that fires `BBSEO_minutely_drain`.
- Reads `config.json` per project (`enabled`, `frequency`, `seed_urls`). Projects without config can still run manually; cron continues draining any pending queue.
- `BBSEO_STEPS_PER_TICK` (env) controls max steps per tick (default 50).
- When a run completes, its `meta.json` is updated with `completed_at` and summary metrics.

## Current Situation / Next Opportunities

- **Implemented**:
  - Run-scoped crawl pipeline (isolated queue/pages/audit/report per run).
  - REST + wp-admin entry points that return `run_id` and operate on explicit runs.
  - 1-minute scheduler with config-driven auto runs and fast queue draining.
  - Run metadata (`meta.json`) capturing lifecycle timestamps and summary metrics.
  - Bootstrap report template capable of switching runs via `?run=` parameter.

- **Known gaps / backlog**:
  - UI to edit `config.json` (frequency, seed URLs) from wp-admin.
  - Comparison/visualization of multiple runs over time.
  - Notifications when new critical issues appear.
  - CLI helpers for bulk draining/testing.
  - AI summaries + PDF/CSV export still pending.

This document reflects the codebase as of **5 Nov 2025**. Use it as the baseline when syncing with additional agents or planning subsequent milestones.
