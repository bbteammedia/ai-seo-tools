<?php
namespace BBSEO\Helpers;

class LLMContext
{
    // Limiters
    const MAX_LIST = 20;          // generic top-N cap
    const MAX_EXAMPLES = 5;       // URLs per section
    const MAX_JSON_BYTES = 6000;  // ~6 KB target per section

    public static function executiveSummary(array $big): array {
        if ($ctx = self::metricsContext($big, 'executive_summary')) {
            return self::capBytes($ctx);
        }
        $r = self::latestRun($big);
        $summary = self::runSummary($r);
        $totals = $summary['totals'] ?? [];
        return self::capBytes([
            'project' => $big['project'] ?? '',
            'type'    => $big['type'] ?? 'general',
            'runs'    => array_map(fn($x)=>$x['run_id'] ?? '', $big['runs']),
            'metrics' => [
                'pages'  => $totals['pages'] ?? $summary['pages'] ?? null,
                'status' => $summary['status'] ?? null,
                'issues_total' => $summary['issues']['total'] ?? null,
            ],
            'top_issues' => self::topIssues($r, 5),
            'ga'  => self::gaTotals($big),
            'gsc' => self::gscTotals($big),
        ]);
    }

    public static function topActions(array $big): array {
        if ($ctx = self::metricsContext($big, 'top_actions')) {
            return self::capBytes($ctx);
        }
        $r = self::latestRun($big);
        $status = self::runSummary($r)['status'] ?? [];
        return self::capBytes([
            'project' => $big['project'] ?? '',
            'impact'  => [
                'errors_4xx'    => $status['4xx'] ?? 0,
                'errors_5xx'    => $status['5xx'] ?? 0,
                'alt_missing'   => self::issueCount($r, 'Images without ALT text'),
                'multi_h1'      => self::issueCount($r, 'Multiple H1 headings'),
                'meta_missing'  => self::issueCount($r, 'Missing meta description'),
            ],
            'examples' => [
                'broken_url' => self::firstUrlWith($r, 'Client error (4xx)'),
                'largest_issue_sample' => self::exampleUrls($r, 'Images without ALT text', 1),
            ],
        ]);
    }

    public static function overview(array $big): array {
        if ($ctx = self::metricsContext($big, 'overview')) {
            return self::capBytes($ctx);
        }
        $r = self::latestRun($big);
        $summary = self::runSummary($r);
        return self::capBytes([
            'pages' => $summary['totals']['pages'] ?? $summary['pages'] ?? null,
            'issues_total' => $summary['issues']['total'] ?? null,
            'status' => $summary['status'] ?? null,
            'issue_buckets' => self::topIssues($r, 6),
            'trend_runs' => array_slice(array_map(function($x){
                return ['run'=>$x['run_id'] ?? '', 'issues'=>$x['issues'] ?? null];
            }, $big['project_scope']['timeseries']['items'] ?? []), -5)
        ]);
    }

    public static function performance(array $big): array {
        if ($ctx = self::metricsContext($big, 'performance_summary')) {
            return self::capBytes($ctx);
        }
        $ga = self::gaTotals($big);
        $r = self::latestRun($big);
        // Hook in real web-vitals if you aggregate them; placeholders here:
        return self::capBytes([
            'core' => ['lcp'=>null,'cls'=>null,'ttfb'=>null],
            'speed_flags' => self::speedFlagsFromAudit($r),
            'ga_top' => [
                'sessions_30d' => $ga['sessions_30d'] ?? null,
                'avg_session_duration' => self::gaAvgSessionDuration($big)
            ],
            'gsc_trend' => self::gscTotals($big)
        ]);
    }

    public static function technicalSEO(array $big): array {
        if ($ctx = self::metricsContext($big, 'technical_seo_issues')) {
            return self::capBytes($ctx);
        }
        $r = self::latestRun($big);
        $summary = self::runSummary($r);
        return self::capBytes([
            'status' => [
                '4xx' => $summary['status']['4xx'] ?? 0,
                '5xx' => $summary['status']['5xx'] ?? 0
            ],
            'canonical_missing' => self::issueCount($r, 'Missing canonical URL'),
            'redirect_chains'   => self::issueCount($r, 'Redirect chain'),
            'h1_missing'        => self::issueCount($r, 'Missing H1 heading'),
            'structured_data_missing' => self::issueCount($r, 'No structured data'),
            'sample_urls' => array_values(array_unique(array_filter([
                self::firstUrlWith($r, 'Client error (4xx)')
            ])))
        ]);
    }

    public static function onpageContent(array $big): array {
        if ($ctx = self::metricsContext($big, 'onpage_seo_content')) {
            return self::capBytes($ctx);
        }
        $r = self::latestRun($big);
        return self::capBytes([
            'title_missing' => self::issueCount($r,'Missing title tag'),
            'meta_missing'  => self::issueCount($r,'Missing meta description'),
            'multi_h1'      => self::issueCount($r,'Multiple H1 headings'),
            'alt_missing'   => self::issueCount($r,'Images without ALT text'),
            'examples'      => self::exampleUrls($r,'Images without ALT text', self::MAX_EXAMPLES)
        ]);
    }

    public static function metaHeading(array $big): array {
        if ($ctx = self::metricsContext($big, 'meta_recommendations')) {
            return self::capBytes($ctx);
        }
        $r = self::latestRun($big);
        return self::capBytes([
            'missing'   => [
                'title' => self::issueCount($r,'Missing title tag'),
                'meta'  => self::issueCount($r,'Missing meta description'),
                'h1'    => self::issueCount($r,'Missing H1 heading')
            ],
            'too_short' => [ 'title' => self::issueCount($r,'Title shorter than 30 characters') ],
            'multi_h1'  => self::issueCount($r,'Multiple H1 headings'),
            'examples'  => self::exampleUrls($r,'Missing meta description', 3)
        ]);
    }

    public static function keywordAnalysis(array $big): array {
        if ($ctx = self::metricsContext($big, 'keyword_analysis')) {
            return self::capBytes($ctx);
        }
        $details = $big['runs'][0]['analytics']['gsc_details']['details'] ?? [];
        $queries = array_slice($details['queries'] ?? [], 0, 10);
        $pages   = array_slice($details['pages'] ?? [], 0, 10);
        $devices = array_slice($details['devices'] ?? [], 0, 3);

        $fmt = fn($row)=>[$row['key'] ?? '', $row['clicks'] ?? 0, $row['impressions'] ?? 0, round($row['ctr'] ?? 0, 2), round($row['position'] ?? 0, 2)];

        return self::capBytes([
            'top_queries' => array_map($fmt, $queries),
            'top_pages'   => array_map($fmt, $pages),
            'devices'     => array_map(fn($d)=>[$d['key'] ?? '', $d['clicks'] ?? 0, $d['impressions'] ?? 0, round($d['ctr'] ?? 0, 2), round($d['position'] ?? 0, 2)], $devices)
        ]);
    }

    // ---- helpers -----------------------------------------------------------

    private static function latestRun(array $big): array { return $big['runs'][array_key_last($big['runs'])] ?? []; }

    private static function issueCount(array $run, string $label): int {
        $counts = self::runIssueCounts($run);
        return (int)($counts[$label] ?? 0);
    }

    private static function topIssues(array $run, int $n): array {
        $counts = self::runIssueCounts($run);
        arsort($counts);
        $out = [];
        foreach (array_slice($counts, 0, $n, true) as $k=>$v) { $out[] = [$k,(int)$v]; }
        return $out;
    }

    private static function firstUrlWith(array $run, string $issue): ?string {
        $items = $run['report']['audit']['items'] ?? $run['audit']['items'] ?? [];
        foreach ($items as $row) {
            if (!empty($row['issues']) && in_array($issue, $row['issues'], true)) {
                return self::shortUrl($row['url'] ?? null);
            }
        }
        return null;
    }

    private static function exampleUrls(array $run, string $issue, int $limit): array {
        $items = $run['report']['audit']['items'] ?? $run['audit']['items'] ?? [];
        $urls = [];
        foreach ($items as $row) {
            if (!empty($row['issues']) && in_array($issue, $row['issues'], true)) {
                $urls[] = self::shortUrl($row['url'] ?? '');
                if (count($urls) >= $limit) break;
            }
        }
        return array_values(array_filter(array_unique($urls)));
    }

    private static function gaTotals(array $big): array {
        $ts = $big['analytics']['ga']['timeseries'] ?? $big['project_scope']['ga_timeseries']['items'] ?? [];
        if (!$ts) return ['sessions_30d'=>null,'pageviews_30d'=>null];
        $sessions = 0; $pageviews = 0;
        foreach ($ts as $d) { $sessions += (int)($d['sessions'] ?? 0); $pageviews += (int)($d['screenPageViews'] ?? 0); }
        return ['sessions_30d'=>$sessions, 'pageviews_30d'=>$pageviews];
    }

    private static function gaAvgSessionDuration(array $big): ?float {
        $ts = $big['analytics']['ga']['timeseries'] ?? $big['project_scope']['ga_timeseries']['items'] ?? [];
        if (!$ts) return null;
        $sum = 0; $n = 0;
        foreach ($ts as $d) { $sum += (float)($d['averageSessionDuration'] ?? 0); $n++; }
        return $n ? round($sum/$n, 2) : null;
    }

    private static function gscTotals(array $big): array {
        $g = $big['analytics']['gsc'] ?? $big['runs'][0]['analytics']['gsc'] ?? null;
        if (!$g || empty($g['totals'])) return ['clicks_30d'=>null,'impressions_30d'=>null,'avg_pos'=>null];
        return [
            'clicks_30d'      => (int)($g['totals']['clicks'] ?? 0),
            'impressions_30d' => (int)($g['totals']['impressions'] ?? 0),
            'avg_pos'         => round((float)($g['totals']['position'] ?? 0), 2),
        ];
        // If only timeseries exists, you can sum like GA.
    }

    private static function speedFlagsFromAudit(array $run): array {
        // Map your audit categories → speed flags; placeholders until you add real signals
        $flags = [];
        if (self::issueCount($run, 'Images without ALT text') > 0) { /* not speed, but sample */ }
        return $flags;
    }

    private static function runSummary(array $run): array
    {
        $summary = [];
        if (!empty($run['summary']) && is_array($run['summary'])) {
            $summary = $run['summary'];
        } elseif (!empty($run['audit']['summary']) && is_array($run['audit']['summary'])) {
            $summary = $run['audit']['summary'];
        } elseif (!empty($run['report']['audit']['summary']) && is_array($run['report']['audit']['summary'])) {
            $summary = $run['report']['audit']['summary'];
        }

        $issueCounts = is_array($summary['issue_counts'] ?? null) ? $summary['issue_counts'] : [];
        if (!isset($summary['issues']) || !is_array($summary['issues'])) {
            $summary['issues'] = [];
        }
        if (!isset($summary['issues']['total'])) {
            $summary['issues']['total'] = self::sumIssueCounts($issueCounts);
        }

        if (!isset($summary['status']) || !is_array($summary['status'])) {
            if (!empty($summary['status_buckets']) && is_array($summary['status_buckets'])) {
                $summary['status'] = $summary['status_buckets'];
            } elseif (!empty($run['aggregations']['status_distribution']) && is_array($run['aggregations']['status_distribution'])) {
                $summary['status'] = $run['aggregations']['status_distribution'];
            } else {
                $summary['status'] = [];
            }
        }

        return $summary;
    }

    private static function runIssueCounts(array $run): array
    {
        $summary = self::runSummary($run);
        $counts = $summary['issue_counts'] ?? [];
        return is_array($counts) ? $counts : [];
    }

    private static function sumIssueCounts(array $counts): int
    {
        $total = 0;
        foreach ($counts as $count) {
            $total += (int) $count;
        }
        return $total;
    }

    private static function metricsContext(array $big, string $sectionKey): ?array
    {
        $metrics = $big['section_metrics'][$sectionKey] ?? null;
        if (!is_array($metrics) || $metrics === []) {
            return null;
        }
        return ['metrics' => $metrics];
    }

    private static function capBytes(array $ctx): array {
        // Serialize & shrink if necessary: drop long arrays, trim strings, etc.
        $json = json_encode($ctx, JSON_UNESCAPED_SLASHES);
        if (strlen($json) <= self::MAX_JSON_BYTES) return $ctx;

        // Simple shrink pass: cut examples and lists
        if (isset($ctx['examples']) && is_array($ctx['examples'])) $ctx['examples'] = array_slice($ctx['examples'], 0, 1);
        if (isset($ctx['issue_buckets'])) $ctx['issue_buckets'] = array_slice($ctx['issue_buckets'], 0, 5);
        if (isset($ctx['top_queries'])) $ctx['top_queries'] = array_slice($ctx['top_queries'], 0, 5);
        if (isset($ctx['top_pages'])) $ctx['top_pages'] = array_slice($ctx['top_pages'], 0, 5);

        // Recheck
        $json = json_encode($ctx, JSON_UNESCAPED_SLASHES);
        return (strlen($json) <= self::MAX_JSON_BYTES) ? $ctx : (json_decode(substr($json, 0, self::MAX_JSON_BYTES), true) ?: $ctx);
    }

    private static function shortUrl(?string $u): ?string {
        if (!$u) return null;
        // Strip tracking params
        $parts = parse_url($u);
        $base  = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').($parts['path'] ?? '');
        return $base;
    }
}
