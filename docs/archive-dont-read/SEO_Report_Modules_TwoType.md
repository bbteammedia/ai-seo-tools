
# 📊 SEO Report Structure & Example Preview

This document outlines the **modular SEO report system**, including all modules, example content, and how they are used in both **Website SEO Analyze & Audit** and **Website Technical SEO Audit** reports.

---

## 🧩 Report Types Overview

| Report Type | Focus / Emphasis | How Modules Differ |
|--------------|-----------------|--------------------|
| **Website SEO Analyze & Audit** | Broader SEO picture – includes content, keywords, traffic & performance. | All modules active; highlight **content**, **keyword**, and **visibility** more. |
| **Website Technical SEO Audit** | Developer-focused – crawling, indexability, and structure issues. | Keyword & backlink sections minimized; focus on **Technical SEO**, **Crawl**, **Performance**, and **Findings**. |

---

## ⚙️ Implementation Notes

Each module contains:
- `overview_text` → short summary or AI-generated insights  
- `recommendations[]` → actionable bullet list  
- `report_type_visibility` → `"seo"`, `"technical"`, or `"both"`  
- `emphasis_score` → 0–1 value to weight visual priority  
- `include_in_pdf` → boolean

**Example JSON schema:**

```json
{
  "module": "Technical SEO Issues",
  "overview_text": "Site crawl revealed indexability and response time problems.",
  "recommendations": ["Fix canonical conflicts", "Reduce TTFB under 1.5s"],
  "report_type_visibility": ["seo", "technical"],
  "emphasis_score": {"seo": 0.6, "technical": 1.0},
  "include_in_pdf": true
}
```

---

## 🔍 Module Focus Map

| Module | Website SEO Analyze & Audit | Website Technical SEO Audit |
|--------|-----------------------------|-----------------------------|
| Executive Summary | ✅ | ✅ |
| Top Actions | ✅ | ✅ |
| Overview | ✅ | ✅ |
| Performance Summary | ✅ (Analytics Focus) | ⚙️ (Load/Speed Focus) |
| Technical SEO Issues | ⚙️ | 🔥 |
| On-Page SEO & Content | 🔥 | ⚙️ |
| Keyword Analysis | 🔥 | ⚪ Optional |
| Backlink Profile | 🔥 | ⚪ Optional |
| Crawl History | ⚙️ | 🔥 |
| Traffic Trends | 🔥 | ⚪ Optional |
| Search Visibility | 🔥 | ⚙️ |
| Meta Recommendations | 🔥 | ⚙️ |
| Technical Findings | ⚙️ | 🔥 |
| Recommendations | ✅ | ✅ |

**Legend:**  
🔥 = Highly emphasized ⚙️ = Present but lower emphasis ⚪ = Optional / Hide in technical-only report

---

## 📘 MODULES & EXAMPLES

Below are all 14 modules with sample data and visualization ideas.  
Each section can include charts, tables, and Gemini-generated summaries.

---

### 🧭 1. Executive Summary
**Purpose:** High-level overview of the entire audit.  
**Visibility:** Both report types

**Data Sources:** `/runs/{run_id}/analytics`, `/audit`

**Example:**
> In the last 30 days, organic sessions rose **+18%** with a **4.3% CTR**. 320 pages indexed.  
> Key issues include slow mobile load and missing meta descriptions.

📊 *KPI Cards:* Traffic, CTR, Avg Position

---

### ⚡ 2. Top Actions
**Purpose:** Quick priorities for immediate impact.  
**Visibility:** Both

| Priority | Action | Impact | Effort |
|-----------|---------|---------|--------|
| 🔴 High | Fix 404 pages (32 found) | ★★★★★ | ★ |
| 🟠 Medium | Add missing meta descriptions (24 URLs) | ★★★★ | ★★ |
| 🟢 Low | Optimize image alt text | ★★ | ★ |

📈 *Chart:* Impact vs Effort Scatter

---

### 🌐 3. Overview
**Purpose:** Crawl and traffic context.  
**Visibility:** Both

| Metric | Value |
|--------|-------|
| Total Pages Crawled | 856 |
| 404 Errors | 12 |
| Avg Response Time | 1.8s |
| Monthly Sessions | 12,480 |
| Bounce Rate | 42% |

📊 *Chart:* HTTP Status Breakdown

---

### 📊 4. Performance Summary
**Purpose:** Combine Analytics + Crawl metrics.  
**Visibility:** Both

| Page | Sessions | CTR | Avg. Position |
|------|-----------|-----|----------------|
| / | 2,134 | 4.2% | 12.3 |
| /blog/ai-seo-tools | 980 | 6.1% | 8.2 |
| /pricing | 740 | 3.9% | 17.5 |

📈 *Chart:* Sessions trend (30 days)

---

### 🧱 5. Technical SEO Issues
**Purpose:** Detect site-wide technical issues.  
**Visibility:** Both (High emphasis for Technical Report)

> Found 12 broken links, 7 canonical mismatches, 16 blocked by robots.txt.  
> Avg response time **2.4s** (target <1.5s).

📊 *Bar Chart:* Error Type Counts

---

### 🧾 6. On-Page SEO & Content
**Purpose:** Meta, headings, and content quality.  
**Visibility:** SEO report focus

| Issue | Count | Example URL |
|--------|--------|-------------|
| Missing Meta Description | 21 | /contact |
| Duplicate Title | 8 | /services-old |
| No H1 Tag | 5 | /news/article-2 |

📊 *Chart:* % Pages with Optimized Metadata

---

### 🔑 7. Keyword Analysis
**Purpose:** Keyword performance and visibility.  
**Visibility:** SEO report only

| Keyword | Clicks | Impressions | CTR | Position |
|----------|---------|-------------|------|----------|
| seo audit tool | 134 | 2,140 | 6.3% | 9.8 |
| website crawl | 72 | 820 | 8.7% | 7.1 |
| ai seo | 55 | 900 | 6.1% | 10.5 |

📈 *Chart:* Avg Position Trend

---

### 🔗 8. Backlink Profile
**Purpose:** Link quality overview.  
**Visibility:** SEO report only

| Domain | Backlinks | Type |
|---------|------------|------|
| example.com | 45 | Follow |
| webmag.io | 18 | NoFollow |
| blogspot.net | 12 | Follow |

📊 *Pie Chart:* Follow vs NoFollow

---

### 🕷️ 9. Crawl History
**Purpose:** Compare multiple runs.  
**Visibility:** Both

| Run ID | Date | Pages | Errors |
|---------|------|-------|--------|
| 2025-10-30 | 856 | 12 |
| 2025-11-07 | 880 | 9 |

📈 *Line Chart:* Crawl Volume Over Time

---

### 📈 10. Traffic Trends
**Purpose:** GA & GSC traffic overview.  
**Visibility:** SEO report

| Date | Clicks | Impressions | CTR |
|------|---------|-------------|------|
| 2025-10-15 | 430 | 8,900 | 4.8% |
| 2025-10-31 | 570 | 10,100 | 5.6% |
| 2025-11-07 | 610 | 10,560 | 5.8% |

📈 *Chart:* CTR Trend (30 Days)

---

### 🔍 11. Search Visibility
**Purpose:** Ranking coverage analysis.  
**Visibility:** Both

| Position Range | Keywords |
|----------------|-----------|
| 1–3 | 12 |
| 4–10 | 37 |
| 11–20 | 65 |
| 21–50 | 102 |

📊 *Bar Chart:* Keyword Position Buckets

---

### 🧠 12. Meta Recommendations
**Purpose:** AI-suggested title/meta updates.  
**Visibility:** Both

| URL | Current Title | Suggested Title |
|-----|----------------|-----------------|
| /services | Our Services | SEO & Digital Services – Boost Visibility |
| /about | About Company | Learn About Us – Your Trusted SEO Partner |

🧠 *AI Tip:* Include primary keyword within first 60 chars.

---

### ⚙️ 13. Technical Findings
**Purpose:** Advanced code-level issues.  
**Visibility:** Both (Technical emphasis)

| Issue | Count |
|--------|--------|
| Canonical mismatch | 8 |
| Missing alt tags | 24 |
| Redirect loop | 3 |

📊 *Chart:* Issue Frequency

---

### 🧩 14. Recommendations
**Purpose:** Concluding action plan.  
**Visibility:** Both

| Priority | Task | Type | Impact |
|-----------|------|------|--------|
| 🔴 High | Fix 404 errors | Technical | ★★★★★ |
| 🟠 Medium | Update outdated titles | On-page | ★★★★ |
| 🟢 Low | Add schema markup | Content | ★★ |

🧠 *Next Steps:* Focus on 404s and duplicate titles for immediate gains.

---

**Author:** Blackbird Media AI SEO Tools  
**Stack:** PHP + Gemini + JSON-based Crawler  
**Generated:** Automated Modular SEO Report Specification
