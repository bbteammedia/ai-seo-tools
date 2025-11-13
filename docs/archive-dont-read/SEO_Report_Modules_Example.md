
# 📊 SEO Report Structure & Example Preview

This document outlines the **modular SEO report system**, detailing data sources, visualizations, and example previews for each section.  
All data is sourced from the folder structure inside `/runs/{run_id}/` and `/analytics`.

---

## 🧭 1. Executive Summary
**Purpose:** High-level overview of the entire audit.

**Data Sources:**
- `runs/{run_id}/analytics` (GA + GSC metrics)
- Aggregated audit data

**Recommended Fields:**
- Summary text (AI-generated via Gemini)
- Key Highlights (traffic, visibility, keyword gains)
- KPI trend cards
- Sparkline mini chart (sessions/clicks)

**Example Preview:**
> In the last 30 days, total organic sessions increased **+18%**, with an average CTR of **4.3%** and over **320 active pages** indexed.  
> Main issues found include missing meta descriptions and slow-loading pages on mobile.  
> Focus next month on optimizing page speed and updating meta tags across top pages.

---

## ⚡ 2. Top Actions
**Purpose:** Quick actionable priorities.

**Data Sources:**
- Aggregated from `/errors`, `/pages`, `/audit`

**Recommended Fields:**
- Top issues ranked by severity
- Impact vs Effort chart
- Action checklist

**Example Preview:**
| Priority | Action | Impact | Effort |
|-----------|---------|---------|--------|
| 🔴 High | Fix 404 pages (32 found) | ★★★★★ | ★ |
| 🟠 Medium | Add missing meta descriptions (24 URLs) | ★★★★ | ★★ |
| 🟢 Low | Optimize image alt text | ★★ | ★ |

---

## 🌐 3. Overview
**Purpose:** General summary of crawl and analytics context.

**Data Sources:**
- `/pages`, `/errors`, `/analytics`

**Recommended Fields:**
- Total pages crawled
- Error summary
- Traffic snapshot

**Example Preview:**
| Metric | Value |
|--------|-------|
| Total Crawled Pages | 856 |
| 404 Errors | 12 |
| Avg Response Time | 1.8s |
| Monthly Sessions | 12,480 |
| Bounce Rate | 42% |

📊 *Chart:* HTTP Status Distribution (200/301/404)

---

## 📊 4. Performance Summary
**Purpose:** Combine Analytics + Crawl performance.

**Data Sources:**
- `/analytics`, `/runs/{run_id}/analytics`

**Recommended Fields:**
- GA metrics: Sessions, CTR, Avg Position
- Device breakdown
- Top performing pages

**Example Preview:**
| Page | Sessions | CTR | Avg. Position |
|------|-----------|-----|----------------|
| / | 2,134 | 4.2% | 12.3 |
| /blog/ai-seo-tools | 980 | 6.1% | 8.2 |
| /pricing | 740 | 3.9% | 17.5 |

📈 *Chart:* Sessions trend over last 30 days

---

## 🧱 5. Technical SEO Issues
**Purpose:** Detect technical problems site-wide.

**Data Sources:**
- `/errors`, `/pages`, `/audit`

**Recommended Fields:**
- Indexability (robots, canonical)
- 404 & redirect loops
- Page speed data

**Example Preview:**
> Found 12 broken links and 7 canonical mismatches.  
> 16 pages blocked by robots.txt.  
> Average response time **2.4s** (target <1.5s).

📊 *Bar Chart:* Error type breakdown

---

## 🧾 6. On-Page SEO & Content
**Purpose:** Title, meta, heading, and content checks.

**Data Sources:**
- `/pages`, `/images`

**Recommended Fields:**
- Missing or duplicate titles
- Word count averages
- Image optimization

**Example Preview:**
| Issue | Count | Example URL |
|--------|--------|-------------|
| Missing Meta Description | 21 | /contact |
| Duplicate Title | 8 | /services-old |
| No H1 Tag | 5 | /news/article-2 |

📊 *Chart:* % Pages with Optimized Metadata

---

## 🔑 7. Keyword Analysis
**Purpose:** Evaluate keyword rankings.

**Data Sources:**
- `/analytics/search-console.json`

**Recommended Fields:**
- Top keywords by clicks/impressions
- Ranking trend

**Example Preview:**
| Keyword | Clicks | Impressions | CTR | Position |
|----------|---------|-------------|------|----------|
| seo audit tool | 134 | 2,140 | 6.3% | 9.8 |
| website crawl | 72 | 820 | 8.7% | 7.1 |
| ai seo | 55 | 900 | 6.1% | 10.5 |

📈 *Chart:* Keyword Ranking Trend

---

## 🔗 8. Backlink Profile
**Purpose:** Backlink metrics (manual upload).

**Data Sources:**
- Manual import (JSON/CSV)

**Recommended Fields:**
- Total backlinks
- Referring domains
- Follow/NoFollow ratio

**Example Preview:**
| Domain | Backlinks | Type |
|---------|------------|------|
| example.com | 45 | Follow |
| webmag.io | 18 | NoFollow |
| blogspot.net | 12 | Follow |

📊 *Pie Chart:* Follow vs NoFollow ratio

---

## 🕷️ 9. Crawl History
**Purpose:** Compare multiple crawl runs.

**Data Sources:**
- `/runs/` (run logs)

**Recommended Fields:**
- Crawl size per run
- New vs missing pages

**Example Preview:**
| Run ID | Date | Pages | Errors |
|---------|------|-------|--------|
| 2025-10-30 | 856 | 12 |
| 2025-11-07 | 880 | 9 |

📈 *Line Chart:* Pages Crawled Over Time

---

## 📈 10. Traffic Trends
**Purpose:** Visualize GA + GSC trends.

**Data Sources:**
- `/analytics`, `/runs/{run_id}/analytics`

**Recommended Fields:**
- Clicks, Impressions, CTR trend
- Top landing pages

**Example Preview:**
| Date | Clicks | Impressions | CTR |
|------|---------|-------------|------|
| 2025-10-15 | 430 | 8,900 | 4.8% |
| 2025-10-31 | 570 | 10,100 | 5.6% |
| 2025-11-07 | 610 | 10,560 | 5.8% |

📈 *Chart:* CTR Trend Over 30 Days

---

## 🔍 11. Search Visibility
**Purpose:** Keyword visibility overview.

**Data Sources:**
- `search-console.json`

**Recommended Fields:**
- Avg. position
- Keyword ranking buckets

**Example Preview:**
| Position Range | Keywords |
|----------------|-----------|
| 1–3 | 12 |
| 4–10 | 37 |
| 11–20 | 65 |
| 21–50 | 102 |

📊 *Bar Chart:* Keyword Position Distribution

---

## 🧠 12. Meta Recommendations
**Purpose:** AI-generated meta tag suggestions.

**Data Sources:**
- `/pages` + Gemini AI generation

**Recommended Fields:**
- Current and suggested title/meta

**Example Preview:**
| URL | Current Title | Suggested Title |
|-----|----------------|-----------------|
| /services | Our Services | SEO & Digital Services – Boost Visibility |
| /about | About Company | Learn About Us – Your Trusted SEO Partner |

🧠 *AI Summary:* “Titles should include a focus keyword within the first 60 characters.”

---

## ⚙️ 13. Technical Findings
**Purpose:** Deep technical data.

**Data Sources:**
- `/pages`, `/errors`

**Recommended Fields:**
- Canonical issues
- Alt text status
- Redirect chains

**Example Preview:**
| Issue | Count |
|--------|--------|
| Canonical mismatch | 8 |
| Missing alt tags | 24 |
| Redirect loop | 3 |

📊 *Bar Chart:* Issue Frequency

---

## 🧩 14. Recommendations
**Purpose:** Final prioritized actions.

**Data Sources:**
- Aggregated + Gemini AI

**Recommended Fields:**
- Tasks by category & priority

**Example Preview:**
| Priority | Task | Type | Impact |
|-----------|------|------|--------|
| 🔴 High | Fix 404 errors | Technical | ★★★★★ |
| 🟠 Medium | Update outdated titles | On-page | ★★★★ |
| 🟢 Low | Add schema markup | Content | ★★ |

🧠 *Next Steps Summary:* Focus on resolving 404s and duplicate titles for immediate SEO uplift.

---

**Author:** Blackbird Media AI SEO Tools  
**Stack:** PHP + Gemini + JSON-based Crawler  
**Generated:** Automated SEO Modular Report Reference
