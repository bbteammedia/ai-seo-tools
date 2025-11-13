# Blackbird SEO Tools (PHP + Gemini)

## Overview
AI-powered SEO automation tool built on **WordPress Bedrock**. It automates monthly SEO reports similar to SEMrush, using Gemini for AI summaries. Designed to run on shared hosting using PHP + JavaScript, with minimal dependencies and JSON-based storage.

---

## Features
- Website crawling (titles, meta, H1, status, etc.)
- Integrate with Google Analytics & Search Console (or CSV upload)
- Keyword & backlink research (manual import + validation)
- SEO audit (site-wide and per-page)
- AI summary using **Gemini**
- Multi-project support (each project = one website)
- Export reports (HTML + PDF)
- Cron/queue system via external uptime monitor
- REST API endpoints using `wp-json`

---

## Architecture

### Stack
- **Base**: WordPress (Roots/Bedrock)
- **App location**: `/web/app/themes/ai-seo-tool/`
- **Storage**: JSON files (no database dependency)
- **AI**: Gemini API
- **Frontend**: Bootstrap + Alpine.js
- **Composer packages**:
  - `spatie/crawler`
  - `symfony/dom-crawler`
  - `guzzlehttp/guzzle`
  - `dompdf/dompdf`
  - `vlucas/phpdotenv`

---

## Folder Structure
```
bedrock/
├── composer.json
├── .env
├── web/
│   ├── wp/
│   ├── app/
│   │   ├── themes/
│   │   │   └── ai-seo-tool/
│   │   │       ├── app/
│   │   │       │   ├── Crawl/
│   │   │       │   ├── Audit/
│   │   │       │   ├── Report/
│   │   │       │   └── Helpers/
│   │   │       ├── cron/
│   │   │       ├── storage/
│   │   │       │   └── projects/
│   │   │       │       └── client-slug/
│   │   │       │           ├── queue/
│   │   │       │           ├── pages/
│   │   │       │           ├── crawl.json
│   │   │       │           └── audit.json
│   │   │       └── templates/
```

---

## Crawling Strategy

Each project folder has a `queue/` directory containing `.todo` files for pending URLs.

**Process flow:**
1. Uptime monitor hits cron endpoint:
   ```
   https://example.com/wp-json/ai-seo-tool/v1/crawl-step?project=client-slug&key=SECURETOKEN
   ```
2. The script picks one `.todo` file.
3. Crawls that single URL using **Spatie Crawler** or **Guzzle**.
4. Saves result as `/pages/{md5(url)}.json`.
5. Moves `.todo` → `.done`.
6. Repeats until queue empty.
7. When complete, another endpoint triggers `audit` + `report` jobs.

---

## Example WP JSON Endpoints

| Endpoint | Description |
|-----------|--------------|
| `/wp-json/ai-seo-tool/v1/start-crawl?project=client-slug` | Initialize crawl queue |
| `/wp-json/ai-seo-tool/v1/crawl-step?project=client-slug` | Process next URL |
| `/wp-json/ai-seo-tool/v1/audit?project=client-slug` | Run SEO audit |
| `/wp-json/ai-seo-tool/v1/report?project=client-slug` | Generate HTML/PDF report |
| `/wp-json/ai-seo-tool/v1/status?project=client-slug` | Get crawl progress |

---

## Reporting Flow
1. **Crawl results** → JSON per page.  
2. **Audit script** → checks SEO rules (title length, meta desc, alt tags, etc.).  
3. **Report script** → compiles audit + analytics + Gemini AI summary.  
4. **Export** → HTML and PDF.

---

## Example Cron Trigger (Uptime Monitor)
UptimeRobot or similar calls every 1–2 minutes:
```
https://example.com/wp-json/ai-seo-tool/v1/crawl-step?project=client-slug&key=SECURETOKEN
```
Each visit processes **1 URL**.  
When finished, system auto-runs `audit` and `report` endpoints.

---

## Benefits
- Works fully on shared hosting
- No database synchronization needed
- Git-friendly (JSON-based data)
- Reliable crawl via external cron ping
- Extendable to plugin or standalone PHP app

---

## Next Steps
1. Initialize Bedrock + Blackbird SEO Tool theme repo.  
2. Install Composer dependencies.  
3. Implement REST endpoints for crawl/audit/report.  
4. Build queue processor (1 URL per cron).  
5. Integrate Gemini for AI summaries.  
6. Add dashboard UI for monitoring progress.

---

**Author:** Adi  
**Stack:** PHP 8.1+, WordPress Bedrock, Gemini API  
**Purpose:** Automated SEO report generator for client projects.
