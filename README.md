# Blackbird SEO Tools

Blackbird SEO Tools is an AI-assisted technical SEO automation suite packaged as a WordPress Bedrock theme. It crawls any site on a shared host, audits every URL, merges Search Console + GA4 metrics, asks Gemini for narrative summaries, and publishes shareable WordPress reports (HTML + PDF) for each client project.

## Highlights
- Queue-based crawler built with Spatie Crawler + Guzzle that stores every page as JSON and survives shared-host limits.
- Rule-driven auditor produces status buckets, issue counts, and per-page findings consumed by the report builder.
- AI sections generated through Gemini 2.5 prompts (customizable in `web/app/themes/ai-seo-tool/templates/prompt`).
- Native WordPress admin surfaces: project CPT, dashboard, run history, section editor, and report CPT with PDF export.
- Google Analytics 4 + Search Console OAuth connectors with CSV/JSON upload fallbacks and time-series storage.
- REST-first workflow so external uptime monitors or workers can drive the crawl/audit/report lifecycle.

## Stack & Layout
- **Bedrock** WordPress skeleton (`web/wp`) with the custom theme in `web/app/themes/ai-seo-tool`.
- **PHP 8.1+** with Composer autoloading (`BBSEO\\` namespace).
- **Key packages**: `spatie/crawler`, `symfony/dom-crawler`, `guzzlehttp/guzzle`, `dompdf/dompdf`, `vlucas/phpdotenv`, `laravel/pint`.
- **Frontend**: Bootstrap utility classes + Alpine.js snippets inside `templates/`.
- **Storage**: JSON files under `BBSEO_STORAGE_DIR` (default `web/app/themes/ai-seo-tool/storage/projects`).

```
web/app/themes/ai-seo-tool/
├── app/
│   ├── Crawl, Audit, Report, AI, Analytics, Rest, Cron ...
│   └── Admin/ (dashboard, run history, report UI)
├── assets/            # CSS/JS bundled with the theme
├── storage/projects/   # default data dir (config, runs, analytics)
├── templates/
│   ├── report.php      # public facing report + PDF renderer
│   └── prompt/*.json   # Gemini prompt blueprints
└── cron/               # future worker scripts
```

## Prerequisites
- PHP 8.1+, Composer 2.x
- MySQL 5.7+/8.0+
- Node is optional (only for custom asset workflows)
- Google Cloud OAuth client (for GA4 + Search Console) and a Gemini API key

## Installation
1. Install Composer dependencies:
   ```bash
   composer install
   ```
2. Copy `.env.example` → `.env` and fill WordPress + Blackbird SEO variables (see table below). Generate salts via https://roots.io/salts.html.
3. Create the database defined in `.env`.
4. Install WordPress (example):
   ```bash
   vendor/bin/wp core install \
     --url="https://seo.local" \
     --title="Blackbird SEO" \
     --admin_user=admin --admin_email=you@example.com --admin_password=secret
   ```
5. Activate the theme: `vendor/bin/wp theme activate ai-seo-tool`.
6. Visit **Blackbird SEO → Projects** in wp-admin, add a project (slug becomes the storage key), and optionally set a crawl schedule.
7. Ensure `DISABLE_WP_CRON` is false (or wire a real cron hitting `wp cron event run --due-now`) so the queue drains automatically.

### Environment variables
| Variable | Purpose |
| --- | --- |
| `BBSEO_SECURE_TOKEN` | Required `key` parameter for every REST call/cron ping.
| `BBSEO_STORAGE_DIR` | Overrides where project JSON (config, runs, analytics) is stored.
| `GEMINI_API_KEY` | Enables AI sections + summaries.
| `BBSEO_HTTP_VERIFY_TLS` | Set to `false` when crawling staging hosts with self-signed certs.
| `BBSEO_DISABLE_CRON` | `true` to pause the WP-Cron queue runner (useful locally).
| `BBSEO_STEPS_PER_TICK` | How many queued URLs to process per cron tick (default 50).
| `BBSEO_GA_CLIENT_ID`, `BBSEO_GA_CLIENT_SECRET` | OAuth credentials for GA4/Search Console connectors.

Standard Bedrock variables (`DB_*`, `WP_ENV`, `WP_HOME`, `WP_SITEURL`, salts, etc.) remain unchanged.

## How the pipeline works
1. **Projects** (`BBSEO_project` CPT) define primary URL, crawl schedule (manual/weekly/monthly), and analytics options.
2. **Queue**: `start-crawl` seeds `.todo` files under `storage/projects/<slug>/runs/<run>/queue`.
3. **Drain**: `BBSEO\Cron\Scheduler` consumes `BBSEO_STEPS_PER_TICK` URLs per minute, or you can hit the REST `crawl-step` endpoint from an external monitor.
4. **Audit & Report**: once the queue is empty the worker runs `Audit\Runner`, `Report\Builder`, `Report\Summary`, appends time-series, syncs analytics, and renders report snapshots.
5. **Reports**: each project can map to an `BBSEO_report` CPT. Public URLs live at `/report/<project>` with on-demand PDF export (`?format=pdf`).

### REST API
All endpoints live under `/wp-json/ai-seo-tool/v1/*` and require `?key=BBSEO_SECURE_TOKEN`.

| Endpoint | Method | Description |
| --- | --- | --- |
| `/start-crawl` | POST | Seed a run. Body accepts `project`, `run_id` (optional), and `urls[]`. Base URL from the project is auto-injected. |
| `/crawl-step` | GET | Process a single queued URL. Pass `project`, optional `run`. Response piggybacks audit/report/summary payloads when the queue empties. |
| `/audit` | POST | Re-run audits for the latest (or supplied) run. |
| `/report` | POST | Rebuild `report.json` for a run without triggering crawl. |
| `/status` | GET | Lightweight queue/readiness stats for dashboards or monitors. |
| `/upload-ga` | POST | Attach GA4 metrics for a run via raw JSON or CSV upload (multipart). |
| `/upload-gsc` | POST | Same as above for Search Console exports. |

Example `crawl-step` using curl:
```bash
curl "https://example.com/wp-json/ai-seo-tool/v1/crawl-step" \
  --get \
  --data-urlencode "project=petalo-cakery" \
  --data-urlencode "key=$BBSEO_SECURE_TOKEN"
```

## Admin workflow
- **Dashboard** (`Blackbird SEO → Dashboard`): shows projects, latest run status, queue counts, quick links to reports, manual run button, and run history.
- **Run History**: lists every run stored in `storage/projects/<slug>/runs/*` with PDF export shortcuts.
- **Reports CPT**: choose project + run(s), reorder/rename AI sections, toggle visibility, and store legacy summaries.
- **Section prompts**: editing files under `templates/prompt/*.json` adjusts the structure, temperature, and schema for Gemini responses per section.

## Chatbot demo
- Front-end lives at `/chatbot-example`. Guests enter their name + email, see every session tied to that email, resume an old transcript, or start fresh.
- Sessions are stored as JSON under `web/app/themes/ai-seo-tool/storage/chatbot/<hash>/sessions/*.json` with timestamps, the visitor’s name, and Gemini responses.
- REST endpoints under `/wp-json/chatbot/v1/*` power the flow:
  - `POST /identify` upserts a profile and returns existing sessions.
  - `GET /sessions?email=` returns a lightweight history list.
  - `POST /session` starts a new conversation and returns its ID.
  - `GET /session?email=&session_id=` fetches the full transcript.
  - `POST /message` appends a user message, calls Gemini, and persists the AI reply.
- The bot can optionally detect when a handoff is needed. Enable “Handoff email automation” in settings, provide team recipients, and the assistant will ask permission + expose a “Send summary” action. Once approved, WordPress emails the transcript summary to the addresses you set.
- Guests can also just type “send this to your team” (or similar) and the chatbot immediately emails the summary without requiring the button click, so both voice and UI flows are covered.
- Configure the AI instructions inside **Blackbird SEO → Chatbot Context** in wp-admin. This text is injected into every prompt so you can describe the company, tone, escalation rules, or data sources.
- Set `GEMINI_API_KEY` in `.env` for real replies. Without it the chatbot politely reports that Gemini access is unavailable.
- Token controls live in the same settings screen: limit max context characters, cap the history window, and toggle running summaries so you can stuff large company knowledge while keeping prompts within budget.
- Inspect stored conversations inside **Blackbird SEO → Chatbot History** where you can filter by email, view summaries, and read transcripts directly in wp-admin.

## Analytics ingestion
1. Configure OAuth Client ID/Secret via `.env`.
2. In the project meta box provide the GA4 property ID and Search Console property.
3. Click “Connect Google Analytics” to go through OAuth; tokens are stored inside each project’s `config.json` under `analytics.ga` or `analytics.gsc`.
4. Automatic sync happens after each crawl via `Analytics\Dispatcher`. Manual uploads via `/upload-ga`/`/upload-gsc` support CSV exports when API access is unavailable.
5. Time-series rollups are stored in `storage/projects/<slug>/analytics/{ga,gsc}-timeseries.json` for charting.

## Storage layout
```
storage/projects/<slug>/
├── config.json                 # schedule, seed URLs, analytics config
├── latest_run.txt
├── analytics/ga-timeseries.json
├── runs/<run_id>/
│   ├── meta.json               # started/completed timestamps
│   ├── pages/*.json            # one file per crawled URL
│   ├── queue/*.todo|*.done
│   ├── audit.json
│   ├── report.json
│   ├── summary.json
│   └── analytics/ga.json / gsc.json
└── timeseries.json             # crawl summary history
```
Set `BBSEO_STORAGE_DIR` to move this tree outside the theme (recommended in production).

## Reports & AI sections
- `Report\Builder` assembles crawl/audit summaries, top issues, analytics snapshots, and project metadata.
- `AI\Gemini` turns those datasets into narrative sections (`executive_summary`, `top_actions`, `technical_findings`, etc.). Prompts live beside `templates/report.php` and can be tweaked per customer.
- The public template (`templates/report.php`) renders HTML cards, charts, AI copy, and meta recommendations plus a Dompdf export. Sample artifacts live in `docs/current-report.html` and `docs/report-template.html`.

## Automation & cron control
- Default worker: WordPress cron event `BBSEO_minutely_drain` defined in `BBSEO\Cron\Scheduler`.
- To offload to an external monitor, disable WP cron (`BBSEO_DISABLE_CRON=true`) and hit `/crawl-step` every minute from UptimeRobot, BetterUptime, etc. One request = one URL processed.
- `BBSEO_STEPS_PER_TICK` and the `.todo` queue ensure long crawls do not exhaust memory/time limits.

## Development & QA
- Code style: `composer lint` (runs Laravel Pint) and `composer lint:fix`.
- WP-CLI config (`wp-cli.yml`) scopes commands to `web/wp` with docroot `web`.
- Docs & fixtures: `docs/data-example.json`, historic HTML exports, and prompt references.
- When changing templates or prompts, keep ASCII output to avoid PDF issues.

## Next steps
- Hook up charts to the analytics time-series JSON.
- Add more automated tests around `app/Audit` and `app/Report` as behavior stabilizes.
- Move long-running crawl workers into a dedicated CLI command (see `web/app/themes/ai-seo-tool/cron/README.md`).
