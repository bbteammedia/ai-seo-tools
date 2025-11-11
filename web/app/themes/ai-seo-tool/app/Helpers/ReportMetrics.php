<?php
namespace BBSEO\Helpers;

use BBSEO\AI\Gemini;

class ReportMetrics
{
    public static function build(string $type, array $snapshot): array
    {
        $context = self::buildAuditContext($type, $snapshot);
        return [
            'executive_summary' => self::buildExecutiveSummary($context),
            'top_actions' => self::buildTopActions($context),
            'overview' => self::buildOverview($context),
            'performance_summary' => self::buildPerformanceSummary($context),
            'technical_seo_issues' => self::buildTechnicalSeoIssues($context),
            'onpage_seo_content' => self::buildOnpageContent($context),
            'keyword_analysis' => self::buildKeywordAnalysis($context),
            'backlink_profile' => self::buildBacklinkProfile($context),
            'crawl_history' => self::buildCrawlHistory($context),
            'traffic_trends' => self::buildTrafficTrends($context),
            'search_visibility' => self::buildSearchVisibility($context),
            'meta_recommendations' => self::buildMetaRecommendations($context),
            'technical_findings' => self::buildTechnicalFindings($context),
            'recommendations' => self::buildRecommendations($context),
        ];
    }

    private static function buildAuditContext(string $type, array $snapshot): array
    {
        $project = $snapshot['project'] ?? '';
        $rawRuns = is_array($snapshot['runs'] ?? null) ? $snapshot['runs'] : [];
        $projectScope = is_array($snapshot['project_scope'] ?? null) ? $snapshot['project_scope'] : [];

        $runs = self::normalizeRuns($rawRuns, $project);

        $context = [
            'type' => $type,
            'project' => $project,
            'runs' => $runs,
            'current_run' => $runs[0] ?? null,
            'previous_run' => $runs[1] ?? null,
            'project_scope' => [
                'crawl_timeseries' => self::normalizeTimeseries($projectScope['timeseries'] ?? null),
                'ga_timeseries' => self::normalizeTimeseries($projectScope['ga_timeseries'] ?? null),
                'gsc_timeseries' => self::normalizeTimeseries($projectScope['gsc_timeseries'] ?? null),
            ],
        ];

        $context['has_ga_or_gsc'] = self::runHasAnalytics($context['current_run']);
        $context['has_gsc'] = self::runHasGsc($context['current_run']);
        $context['has_backlink_data'] = !empty($context['current_run']['audit']['backlinks']);

        return $context;
    }

    private static function normalizeRuns(array $rawRuns, string $project): array
    {
        $runs = [];
        foreach ($rawRuns as $rawRun) {
            if (!is_array($rawRun)) {
                continue;
            }
            $runId = (string) ($rawRun['run_id'] ?? '');
            if ($runId === '') {
                continue;
            }

            $audit = is_array($rawRun['audit'] ?? null) ? $rawRun['audit'] : [];
            $analytics = is_array($rawRun['analytics'] ?? null) ? $rawRun['analytics'] : [];
            $counts = self::runDirectoryCounts($project, $runId);

            $runs[] = [
                'run_id' => $runId,
                'audit' => $audit,
                'analytics' => $analytics,
                'counts' => $counts,
                'aggregations' => is_array($audit['aggregations'] ?? null) ? $audit['aggregations'] : [],
                'issues' => is_array($audit['issues'] ?? null) ? $audit['issues'] : [],
                'items' => is_array($audit['items'] ?? null) ? $audit['items'] : [],
                'diff' => is_array($audit['diff'] ?? null) ? $audit['diff'] : [],
            ];
        }

        usort($runs, static fn ($a, $b) => self::compareRunDates($a, $b));
        return array_values($runs);
    }

    private static function runDirectoryCounts(string $project, string $runId): array
    {
        if ($project === '') {
            return [
                'pages' => 0,
                'images' => 0,
                'errors' => 0,
            ];
        }

        $runDir = Storage::runDir($project, $runId);
        return [
            'pages' => self::countDirectoryFiles($runDir . '/pages'),
            'images' => self::countDirectoryFiles($runDir . '/images'),
            'errors' => self::countDirectoryFiles($runDir . '/errors'),
        ];
    }

    private static function countDirectoryFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    private static function compareRunDates(array $a, array $b): int
    {
        $aTime = self::runTimestamp($a);
        $bTime = self::runTimestamp($b);
        if ($aTime === $bTime) {
            return 0;
        }
        return $aTime > $bTime ? -1 : 1;
    }

    private static function runTimestamp(array $run): int
    {
        $date = $run['audit']['run']['finished_at'] ?? $run['audit']['generated_at'] ?? '';
        $timestamp = strtotime((string) $date);
        return $timestamp ? (int) $timestamp : 0;
    }

    private static function runHasAnalytics(?array $run): bool
    {
        if (!$run) {
            return false;
        }
        if (!empty($run['analytics']['ga'] ?? null)) {
            return true;
        }
        if (!empty($run['analytics']['gsc'] ?? null)) {
            return true;
        }
        $details = self::getByPath($run, 'analytics.gsc_details.details.queries');
        return is_array($details) && !empty($details);
    }

    private static function runHasGsc(?array $run): bool
    {
        if (!$run) {
            return false;
        }
        if (!empty($run['analytics']['gsc'] ?? null)) {
            return true;
        }
        $details = self::getByPath($run, 'analytics.gsc_details.details.queries');
        return is_array($details) && !empty($details);
    }

    private static function buildExecutiveSummary(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Executive summary not available yet.');
        }

        $rows = [];
        $rows[] = ['Metric' => 'Project', 'Value' => $context['project'] ?: '-'];
        $rows[] = ['Metric' => 'Run ID', 'Value' => $run['run_id']];
        $generated = $run['audit']['generated_at'] ?? $run['audit']['run']['finished_at'] ?? '';
        $rows[] = ['Metric' => 'Generated', 'Value' => self::formatDateLabel((string) $generated)];

        $rows[] = ['Metric' => 'Total Pages', 'Value' => self::formatNumber($run['counts']['pages'])];
        $rows[] = ['Metric' => 'Indexable Pages', 'Value' => self::formatNumber(self::countIndexablePages($run))];

        $avgLoad = self::averageLoadTime($run);
        $rows[] = ['Metric' => 'Avg Load Time', 'Value' => self::formatLoadTime($avgLoad)];

        $topIssues = self::topIssues($run, 3);
        if (!empty($topIssues)) {
            $rows[] = [
                'Metric' => 'Top Issue Types',
                'Value' => implode(', ', array_map(static fn ($issue) => ($issue['name'] ?? '-') . ' (' . self::formatNumber($issue['occurrences'] ?? null) . ')', $topIssues)),
            ];
        }

        $ga = self::collectGaSummary($run);
        if (!empty($ga['sessions']) || !empty($ga['users']) || $ga['bounce'] !== null) {
            $gaValue = implode(', ', array_filter([
                $ga['sessions'] !== null ? 'Sessions: ' . self::formatNumber($ga['sessions']) : null,
                $ga['users'] !== null ? 'Users: ' . self::formatNumber($ga['users']) : null,
                $ga['bounce'] !== null ? 'Bounce: ' . self::formatPercent($ga['bounce']) : null,
            ]));
            if ($gaValue !== '') {
                $rows[] = ['Metric' => 'Google Analytics (30d)', 'Value' => $gaValue];
            }
        }

        $gsc = self::collectGscSummary($run);
        if ($gsc['clicks'] !== null || $gsc['impressions'] !== null || $gsc['ctr'] !== null || $gsc['position'] !== null) {
            $gscValue = implode(', ', array_filter([
                $gsc['clicks'] !== null ? 'Clicks: ' . self::formatNumber($gsc['clicks']) : null,
                $gsc['impressions'] !== null ? 'Impressions: ' . self::formatNumber($gsc['impressions']) : null,
                $gsc['ctr'] !== null ? 'CTR: ' . self::formatPercent($gsc['ctr']) : null,
                $gsc['position'] !== null ? 'Avg Pos: ' . self::formatNumber($gsc['position'], true) : null,
            ]));
            if ($gscValue !== '') {
                $rows[] = ['Metric' => 'Google Search Console (30d)', 'Value' => $gscValue];
            }
        }

        $note = '';
        if (empty($rows)) {
            return self::emptyTable('Executive summary requires crawl data.');
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => $note,
        ];
    }

    private static function buildTopActions(array $context): array
    {
        $run = $context['current_run'];
        if (!$run || empty($run['issues'])) {
            return self::emptyTable('Top actions require crawl issues to be populated.');
        }

        $issues = self::topIssues($run, 5);
        if (empty($issues)) {
            return self::emptyTable('Top actions require issue metadata.');
        }

        $rows = [];
        foreach ($issues as $issue) {
            $rows[] = [
                'Issue' => $issue['name'] ?? '- ',
                'Severity' => ucfirst((string) ($issue['severity'] ?? '-')),
                'Impact' => self::formatNumber($issue['impact'] ?? null, true) ?: 'N/A',
                'Occurrences' => self::formatNumber($issue['occurrences'] ?? null) ?: '0',
                'Priority Score' => number_format(self::issuePriority($issue), 1),
                'Example URL' => !empty($issue['sample_urls']) ? self::shortUrl($issue['sample_urls'][0]) : '-',
            ];
        }

        $diff = $run['diff'] ?? [];
        $noteParts = [];
        if (isset($diff['issues_resolved'])) {
            $noteParts[] = 'Resolved: ' . self::formatNumber($diff['issues_resolved']) . ' issues';
        }
        if (isset($diff['issues_regressed'])) {
            $noteParts[] = 'Regressed: ' . self::formatNumber($diff['issues_regressed']) . ' issues';
        }

        return [
            'headers' => ['Issue', 'Severity', 'Impact', 'Occurrences', 'Priority Score', 'Example URL'],
            'rows' => $rows,
            'empty' => '',
            'note' => implode(' · ', array_filter($noteParts)),
        ];
    }

    private static function buildOverview(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Overview metrics unavailable until a crawl completes.');
        }

        $duration = $run['audit']['run']['duration_sec'] ?? null;
        $status = $run['aggregations']['status_distribution'] ?? [];
        $indexability = $run['aggregations']['indexability'] ?? [];
        $imageCoverage = $run['aggregations']['image_alt_coverage'] ?? null;
        $avgLoad = self::averageLoadTime($run);

        $statusLabel = [];
        foreach (['2xx', '3xx', '4xx', '5xx', 'other'] as $bucket) {
            if (isset($status[$bucket])) {
                $statusLabel[] = $bucket . ': ' . self::formatNumber($status[$bucket]);
            }
        }

        $indexableCount = self::countIndexablePages($run);
        $indexNote = [
            'Indexable: ' . self::formatNumber($indexableCount),
        ];
        if (isset($indexability['canonicalized'])) {
            $indexNote[] = 'Canonicalized: ' . self::formatNumber($indexability['canonicalized']);
        }
        if (isset($indexability['noindex'])) {
            $indexNote[] = 'Noindex: ' . self::formatNumber($indexability['noindex']);
        }
        if (isset($indexability['blocked_robots'])) {
            $indexNote[] = 'Robots-blocked: ' . self::formatNumber($indexability['blocked_robots']);
        }

        $rows = [
            ['Metric' => 'Crawl Duration', 'Value' => self::formatDuration($duration)],
            ['Metric' => 'Pages Crawled', 'Value' => self::formatNumber($run['counts']['pages'])],
            ['Metric' => 'HTTP Status Distribution', 'Value' => implode(', ', $statusLabel) ?: 'n/a'],
            ['Metric' => 'Indexability', 'Value' => implode(' · ', $indexNote)],
            ['Metric' => 'Avg Load Time', 'Value' => self::formatLoadTime($avgLoad)],
            ['Metric' => 'Images Missing ALT Coverage', 'Value' => $imageCoverage !== null ? self::formatPercent($imageCoverage) : 'N/A'],
            ['Metric' => 'Errors Captured', 'Value' => self::formatNumber($run['counts']['errors']) ?: '0'],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildPerformanceSummary(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Performance summary requires crawl data.');
        }

        $ga = self::collectGaSummary($run);
        $gsc = self::collectGscSummary($run);
        $rows = [];
        $notes = [];

        if ($ga['sessions'] !== null || $ga['users'] !== null) {
            $rows[] = [
                'Metric' => 'GA Sessions / Users',
                'Value' => implode(', ', array_filter([
                    $ga['sessions'] !== null ? 'Sessions: ' . self::formatNumber($ga['sessions']) : null,
                    $ga['users'] !== null ? 'Users: ' . self::formatNumber($ga['users']) : null,
                ])),
            ];
            if ($ga['bounce'] !== null) {
                $rows[] = ['Metric' => 'GA Bounce Rate', 'Value' => self::formatPercent($ga['bounce'])];
            }
            if (!empty($ga['top_pages'])) {
                $rows[] = ['Metric' => 'Top Pages by Sessions', 'Value' => implode(', ', array_map(static fn ($url) => self::shortUrl($url), array_slice($ga['top_pages'], 0, 5))) ?: '—'];
            }
        } else {
            $avgLoad = self::averageLoadTime($run);
            $rows[] = ['Metric' => 'Crawler Perf (fallback)', 'Value' => 'Avg load: ' . self::formatLoadTime($avgLoad)];
            $notes[] = 'GA data not yet connected; showing crawl performance instead.';
        }

        if ($gsc['clicks'] !== null || $gsc['impressions'] !== null) {
            $rows[] = [
                'Metric' => 'GSC Clicks / Impressions',
                'Value' => implode(', ', array_filter([
                    $gsc['clicks'] !== null ? 'Clicks: ' . self::formatNumber($gsc['clicks']) : null,
                    $gsc['impressions'] !== null ? 'Impressions: ' . self::formatNumber($gsc['impressions']) : null,
                ])),
            ];
            if ($gsc['ctr'] !== null || $gsc['position'] !== null) {
                $rows[] = ['Metric' => 'GSC CTR / Position', 'Value' => implode(', ', array_filter([
                    $gsc['ctr'] !== null ? 'CTR: ' . self::formatPercent($gsc['ctr']) : null,
                    $gsc['position'] !== null ? 'Avg Pos: ' . self::formatNumber($gsc['position'], true) : null,
                ]))];
            }
        } else {
            $notes[] = 'GSC data unavailable for this run.';
        }

        if (empty($rows)) {
            return self::emptyTable('Performance summary requires analytics data.');
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => implode(' · ', array_filter($notes)),
        ];
    }

    private static function buildTechnicalSeoIssues(array $context): array
    {
        $run = $context['current_run'];
        if (!$run || empty($run['issues'])) {
            return self::emptyTable('Technical issues will appear after the crawler finishes.');
        }

        $issues = $run['issues'];
        $rows = [];
        foreach (array_slice($issues, 0, 12) as $issue) {
            $rows[] = [
                'Issue' => $issue['name'] ?? '-',
                'Severity' => ucfirst((string) ($issue['severity'] ?? '-')),
                'Occurrences' => self::formatNumber($issue['occurrences'] ?? null) ?: '0',
                'Category' => $issue['category'] ?? '-',
                'Sample URL' => !empty($issue['sample_urls']) ? self::shortUrl($issue['sample_urls'][0]) : '-',
            ];
        }

        $severity = $run['aggregations']['issue_by_severity'] ?? self::buildSeverityCounts($run['issues']);
        $severityLabel = [];
        foreach ($severity as $level => $count) {
            $severityLabel[] = ucfirst($level) . ': ' . self::formatNumber($count);
        }

        $indexability = $run['aggregations']['indexability'] ?? [];
        $indexLabel = [];
        if (isset($indexability['canonicalized'])) {
            $indexLabel[] = 'Canonicalized: ' . self::formatNumber($indexability['canonicalized']);
        }
        if (isset($indexability['noindex'])) {
            $indexLabel[] = 'Noindex: ' . self::formatNumber($indexability['noindex']);
        }
        if (isset($indexability['blocked_robots'])) {
            $indexLabel[] = 'Blocked by robots: ' . self::formatNumber($indexability['blocked_robots']);
        }

        $noteParts = [];
        if ($severityLabel) {
            $noteParts[] = 'Severity distribution: ' . implode(', ', $severityLabel);
        }
        if ($indexLabel) {
            $noteParts[] = 'Indexability: ' . implode(', ', $indexLabel);
        }

        return [
            'headers' => ['Issue', 'Severity', 'Occurrences', 'Category', 'Sample URL'],
            'rows' => $rows,
            'empty' => '',
            'note' => implode(' · ', array_filter($noteParts)),
        ];
    }

    private static function buildOnpageContent(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('On-page content metrics will appear after the crawl runs.');
        }

        $items = $run['items'] ?? [];
        $missingTitles = 0;
        $missingMeta = 0;
        $multiH1 = 0;
        $thinPages = 0;
        $imagesMissingAlt = 0;
        $imageTotal = 0;

        foreach ($items as $item) {
            $meta = $item['meta'] ?? [];
            if (empty(trim((string) ($meta['title'] ?? '')))) {
                $missingTitles++;
            }
            if (empty(trim((string) ($meta['meta_description'] ?? '')))) {
                $missingMeta++;
            }
            $h1Count = $meta['h1_count'] ?? null;
            if (is_numeric($h1Count) && (int) $h1Count > 1) {
                $multiH1++;
            }

            $content = $item['content'] ?? [];
            if (!empty($content['thin_content'])) {
                $thinPages++;
            }

            $media = $item['media'] ?? [];
            if (isset($media['image_count'])) {
                $imageTotal += (int) $media['image_count'];
            }
            if (isset($media['images_missing_alt'])) {
                $imagesMissingAlt += (int) $media['images_missing_alt'];
            }
        }

        $wordBins = $run['aggregations']['wordcount_bins'] ?? [];
        $wordLabel = [];
        foreach ($wordBins as $bucket => $value) {
            $wordLabel[] = $bucket . ': ' . self::formatNumber($value);
        }

        $imageCoverage = $run['aggregations']['image_alt_coverage'] ?? null;
        $coverageLabel = $imageCoverage !== null ? self::formatPercent($imageCoverage) : 'N/A';

        $rows = [
            ['Metric' => 'Missing Title Tags', 'Value' => self::formatNumber($missingTitles) ?: '0'],
            ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($missingMeta) ?: '0'],
            ['Metric' => 'Pages with Multiple H1s', 'Value' => self::formatNumber($multiH1) ?: '0'],
            ['Metric' => 'Word Count Distribution', 'Value' => implode(', ', $wordLabel) ?: 'Data unavailable'],
            ['Metric' => 'Thin Content Pages', 'Value' => self::formatNumber($thinPages) ?: '0'],
            ['Metric' => 'Duplicate Content Groups', 'Value' => 'Data unavailable'],
            ['Metric' => 'Images Missing ALT', 'Value' => ($imagesMissingAlt ?: '0') . ' missing / ' . ($imageTotal ?: '0') . ' total (' . $coverageLabel . ')'],
            ['Metric' => 'Captured Images', 'Value' => self::formatNumber($run['counts']['images']) ?: '0'],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildKeywordAnalysis(array $context): array
    {
        if (empty($context['has_gsc'])) {
            return self::emptyTable('Keyword analysis requires Google Search Console to be connected.');
        }

        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Keyword analysis requires crawl data.');
        }

        $gsc = self::collectGscSummary($run);
        $rows = [];
        $rows[] = ['Metric' => 'Clicks (30d)', 'Value' => self::formatNumber($gsc['clicks']) ?: '0'];
        $rows[] = ['Metric' => 'Impressions (30d)', 'Value' => self::formatNumber($gsc['impressions']) ?: '0'];
        $rows[] = ['Metric' => 'CTR (30d)', 'Value' => $gsc['ctr'] !== null ? self::formatPercent($gsc['ctr']) : 'N/A'];
        $rows[] = ['Metric' => 'Avg Position (30d)', 'Value' => $gsc['position'] !== null ? self::formatNumber($gsc['position'], true) : 'N/A'];

        $queries = self::collectGscQueries($run, 5);
        if (!empty($queries)) {
            foreach ($queries as $query) {
                $rows[] = [
                    'Metric' => 'Query: ' . ($query['key'] ?? '-'),
                    'Value' => implode(', ', array_filter([
                        isset($query['clicks']) ? 'Clicks: ' . self::formatNumber($query['clicks']) : null,
                        isset($query['impressions']) ? 'Impr: ' . self::formatNumber($query['impressions']) : null,
                        isset($query['ctr']) ? 'CTR: ' . self::formatPercent($query['ctr']) : null,
                        isset($query['position']) ? 'Pos: ' . self::formatNumber($query['position'], true) : null,
                    ])),
                ];
            }
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildBacklinkProfile(array $context): array
    {
        if (empty($context['has_backlink_data'])) {
            return self::emptyTable('Backlink profile requires third-party backlink data.');
        }

        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Backlink profile requires crawl data.');
        }

        $backlinks = $run['audit']['backlinks'] ?? [];
        if (empty($backlinks)) {
            return self::emptyTable('Backlink provider data not found.');
        }

        $rows = [
            ['Metric' => 'Total Backlinks', 'Value' => self::formatNumber($backlinks['total_backlinks'] ?? null) ?: 'N/A'],
            ['Metric' => 'Referring Domains', 'Value' => self::formatNumber($backlinks['referring_domains'] ?? null) ?: 'N/A'],
        ];
        if (isset($backlinks['follow_ratio'])) {
            $rows[] = ['Metric' => 'Follow Ratio', 'Value' => self::formatPercent($backlinks['follow_ratio'])];
        }
        if (isset($backlinks['anchor_distribution'])) {
            $distribution = is_array($backlinks['anchor_distribution']) ? implode(', ', array_keys($backlinks['anchor_distribution'])) : 'Available';
            $rows[] = ['Metric' => 'Anchor Distribution', 'Value' => $distribution];
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildCrawlHistory(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Crawl history requires run data.');
        }

        $diff = $run['diff'] ?? [];
        $rows = [
            ['Metric' => 'New Pages', 'Value' => self::formatNumber($diff['new_pages'] ?? null) ?: '0'],
            ['Metric' => 'Removed Pages', 'Value' => self::formatNumber($diff['removed_pages'] ?? null) ?: '0'],
            ['Metric' => 'Issues Resolved', 'Value' => self::formatNumber($diff['issues_resolved'] ?? null) ?: '0'],
            ['Metric' => 'Issues Regressed', 'Value' => self::formatNumber($diff['issues_regressed'] ?? null) ?: '0'],
            ['Metric' => 'Pages Crawled This Run', 'Value' => self::formatNumber($run['counts']['pages']) ?: '0'],
            ['Metric' => 'Images Crawled This Run', 'Value' => self::formatNumber($run['counts']['images']) ?: '0'],
        ];

        $noteParts = [];
        $previous = $context['previous_run'];
        if ($previous) {
            $noteParts[] = 'Pages delta vs prev: ' . self::formatDelta($run['counts']['pages'] ?? 0, $previous['counts']['pages'] ?? 0);
            $noteParts[] = 'Images delta vs prev: ' . self::formatDelta($run['counts']['images'] ?? 0, $previous['counts']['images'] ?? 0);
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => implode(' · ', array_filter($noteParts)),
        ];
    }

    private static function buildTrafficTrends(array $context): array
    {
        $rows = [];
        $notes = [];

        $gaSeries = $context['project_scope']['ga_timeseries'];
        if (!empty($gaSeries)) {
            $rows[] = [
                'Metric' => 'GA Sessions Trend',
                'Value' => self::formatTimeseriesDelta($gaSeries, 'sessions'),
            ];
            if (isset($gaSeries[array_key_last($gaSeries)]['bounceRate'])) {
                $rows[] = [
                    'Metric' => 'GA Bounce Rate Trend',
                    'Value' => self::formatTimeseriesDelta($gaSeries, 'bounceRate', true),
                ];
            }
        } else {
            $notes[] = 'No GA timeseries available yet.';
        }

        $gscSeries = $context['project_scope']['gsc_timeseries'];
        if (!empty($gscSeries)) {
            $rows[] = [
                'Metric' => 'GSC Clicks Trend',
                'Value' => self::formatTimeseriesDelta($gscSeries, 'clicks'),
            ];
            $rows[] = [
                'Metric' => 'GSC Impressions Trend',
                'Value' => self::formatTimeseriesDelta($gscSeries, 'impressions'),
            ];
            $rows[] = [
                'Metric' => 'GSC CTR Trend',
                'Value' => self::formatTimeseriesDelta($gscSeries, 'ctr', true),
            ];
        } else {
            $notes[] = 'No GSC timeseries available yet.';
        }

        $gaSummary = $context['current_run'] ? self::collectGaSummary($context['current_run']) : [];
        if (!empty($gaSummary['top_pages'])) {
            $notes[] = 'GA top pages: ' . implode(', ', array_map(static fn ($url) => self::shortUrl($url), array_slice($gaSummary['top_pages'], 0, 3)));
        }

        if (empty($rows)) {
            return self::emptyTable('Traffic trends require historical analytics.');
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => implode(' · ', array_filter($notes)),
        ];
    }

    private static function buildSearchVisibility(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Search visibility needs crawl data.');
        }

        $gsc = self::collectGscSummary($run);
        $totalPages = $run['counts']['pages'] ?? 0;
        $indexable = self::countIndexablePages($run);

        $rows = [
            ['Metric' => 'GSC Avg Position', 'Value' => $gsc['position'] !== null ? self::formatNumber($gsc['position'], true) : 'Connect GSC'],
            ['Metric' => 'GSC CTR', 'Value' => $gsc['ctr'] !== null ? self::formatPercent($gsc['ctr']) : 'Connect GSC'],
            ['Metric' => 'Indexable vs Total', 'Value' => self::formatNumber($indexable) . ' / ' . self::formatNumber($totalPages) . ' (' . self::formatPercent($totalPages ? $indexable / $totalPages : 0) . ')'],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildMetaRecommendations(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Meta recommendations require crawl data.');
        }

        $recommendations = self::collectMetaRecommendations($run, 5);
        if (empty($recommendations)) {
            return self::emptyTable('AI meta recommendations are not available for this run.');
        }

        return [
            'headers' => ['URL', 'Current Title', 'AI Title', 'Current Meta', 'AI Meta'],
            'rows' => $recommendations,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildTechnicalFindings(array $context): array
    {
        $run = $context['current_run'];
        if (!$run) {
            return self::emptyTable('Technical findings require crawl data.');
        }

        $items = $run['items'] ?? [];
        $canonicalCount = 0;
        $hreflangCount = 0;
        $noindex = 0;
        $blocked = 0;
        $https = 0;
        $mixed = 0;
        $structuredCount = 0;
        $ttfb = [];
        $lcp = [];
        $weight = [];

        foreach ($items as $item) {
            $index = $item['indexability'] ?? [];
            if (!empty($index['is_canonicalized'])) {
                $canonicalCount++;
            }
            if (!empty($index['is_noindex'])) {
                $noindex++;
            }
            if (!empty($index['is_blocked_by_robots'])) {
                $blocked++;
            }

            $meta = $item['meta'] ?? [];
            if (!empty($meta['hreflang'])) {
                $hreflangCount++;
            }

            $security = $item['security'] ?? [];
            if (!empty($security['is_https'])) {
                $https++;
            }
            if (!empty($security['mixed_content'])) {
                $mixed++;
            }

            if (!empty($item['structured_data'])) {
                $structuredCount++;
            }

            $perf = $item['performance'] ?? [];
            if (isset($perf['ttfb_ms'])) {
                $ttfb[] = (float) $perf['ttfb_ms'];
            }
            if (isset($perf['lcp_ms'])) {
                $lcp[] = (float) $perf['lcp_ms'];
            }
            if (isset($perf['page_weight_kb'])) {
                $weight[] = (float) $perf['page_weight_kb'];
            }
        }

        $structuredPct = $items ? ($structuredCount / count($items)) : 0;
        $avgWeight = empty($weight) ? null : (array_sum($weight) / count($weight));
        $rows = [
            ['Metric' => 'Canonicalized Pages', 'Value' => self::formatNumber($canonicalCount)],
            ['Metric' => 'Hreflang Tags', 'Value' => self::formatNumber($hreflangCount)],
            ['Metric' => 'Robots Flags', 'Value' => 'Noindex: ' . self::formatNumber($noindex) . ', Blocked: ' . self::formatNumber($blocked)],
            ['Metric' => 'HTTPS vs Mixed Content', 'Value' => 'HTTPS: ' . self::formatNumber($https) . ', Mixed: ' . self::formatNumber($mixed)],
            ['Metric' => 'Structured Data Coverage', 'Value' => self::formatPercent($structuredPct)],
            ['Metric' => 'Page Speed Averages', 'Value' => 'TTFB: ' . self::formatMsAverage($ttfb) . ', LCP: ' . self::formatMsAverage($lcp) . ', Weight: ' . ($avgWeight !== null ? self::formatNumber($avgWeight, true) . ' KB' : 'N/A')],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => '',
            'note' => '',
        ];
    }

    private static function buildRecommendations(array $context): array
    {
        $run = $context['current_run'];
        if (!$run || empty($run['issues'])) {
            return self::emptyTable('Recommendations need issues from the latest crawl.');
        }

        $grouped = [];
        foreach ($run['issues'] as $issue) {
            $category = $issue['category'] ?? 'General';
            $grouped[$category][] = $issue;
        }

        $rows = [];
        foreach ($grouped as $category => $items) {
            $top = self::topIssues(['issues' => $items], 1);
            $topIssue = $top[0] ?? null;
            if (!$topIssue) {
                continue;
            }
            $rows[] = [
                'Category' => $category,
                'Top Issue' => $topIssue['name'] ?? '-',
                'Severity' => ucfirst((string) ($topIssue['severity'] ?? '-')),
                'Occurrences' => self::formatNumber($topIssue['occurrences'] ?? null) ?: '0',
            ];
        }

        $diff = $run['diff'] ?? [];
        $noteParts = [];
        if (isset($diff['issues_resolved'])) {
            $noteParts[] = 'Resolved: ' . self::formatNumber($diff['issues_resolved']);
        }
        if (isset($diff['issues_regressed'])) {
            $noteParts[] = 'Regressed: ' . self::formatNumber($diff['issues_regressed']);
        }

        return [
            'headers' => ['Category', 'Top Issue', 'Severity', 'Occurrences'],
            'rows' => $rows,
            'empty' => '',
            'note' => implode(' · ', array_filter($noteParts)),
        ];
    }

    private static function countIndexablePages(array $run): int
    {
        $items = $run['items'] ?? [];
        $count = 0;
        foreach ($items as $item) {
            $isIndexable = $item['indexability']['is_indexable'] ?? null;
            if ($isIndexable) {
                $count++;
            }
        }
        return $count;
    }

    private static function averageLoadTime(array $run): ?float
    {
        $items = $run['items'] ?? [];
        $values = [];
        foreach ($items as $item) {
            $perf = $item['performance'] ?? [];
            if (isset($perf['load_time_s']) && is_numeric($perf['load_time_s'])) {
                $values[] = (float) $perf['load_time_s'];
                continue;
            }
            if (isset($perf['load_time_ms']) && is_numeric($perf['load_time_ms'])) {
                $values[] = (float) $perf['load_time_ms'] / 1000;
            }
        }
        if (empty($values)) {
            return null;
        }
        return array_sum($values) / count($values);
    }

    private static function formatLoadTime(?float $seconds): string
    {
        if ($seconds === null) {
            return 'N/A';
        }
        return number_format($seconds, 2) . 's';
    }

    private static function formatDuration($seconds): string
    {
        if (!is_numeric($seconds) || (int) $seconds <= 0) {
            return 'N/A';
        }
        $seconds = (int) $seconds;
        $parts = [];
        $hours = intdiv($seconds, 3600);
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        $minutes = intdiv($seconds % 3600, 60);
        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }
        $rest = $seconds % 60;
        if ($rest > 0 || empty($parts)) {
            $parts[] = $rest . 's';
        }
        return implode(' ', $parts);
    }

    private static function topIssues(array $run, int $limit = 3): array
    {
        $issues = $run['issues'] ?? [];
        if (empty($issues)) {
            return [];
        }
        usort($issues, static fn ($a, $b) => self::issuePriority($b) <=> self::issuePriority($a));
        return array_slice($issues, 0, $limit);
    }

    private static function issuePriority(array $issue): float
    {
        $weights = [
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
        ];
        $severity = strtolower($issue['severity'] ?? '');
        $weight = $weights[$severity] ?? 1;
        $impact = isset($issue['impact']) ? (float) $issue['impact'] : 1.0;
        $occurrences = isset($issue['occurrences']) ? (float) $issue['occurrences'] : 0;
        return $weight * max(1.0, $impact) * max(1.0, $occurrences);
    }

    private static function collectGaSummary(array $run): array
    {
        $summary = $run['audit']['analytics_summary']['ga'] ?? [];
        if (!empty($summary)) {
            return [
                'sessions' => $summary['sessions_30d'] ?? null,
                'users' => $summary['users_30d'] ?? null,
                'bounce' => $summary['bounce_rate'] ?? null,
                'top_pages' => $summary['top_pages'] ?? [],
            ];
        }

        $analytics = $run['analytics']['ga'] ?? [];
        $totals = $analytics['totals'] ?? $analytics;
        return [
            'sessions' => $totals['sessions'] ?? null,
            'users' => $totals['totalUsers'] ?? null,
            'bounce' => $totals['bounceRate'] ?? null,
            'top_pages' => $analytics['top_pages'] ?? [],
        ];
    }

    private static function collectGscSummary(array $run): array
    {
        $summary = $run['audit']['analytics_summary']['gsc'] ?? [];
        if (!empty($summary)) {
            return [
                'clicks' => $summary['clicks_30d'] ?? null,
                'impressions' => $summary['impressions_30d'] ?? null,
                'ctr' => $summary['ctr_30d'] ?? $summary['ctr'] ?? null,
                'position' => $summary['avg_position'] ?? $summary['position'] ?? null,
            ];
        }

        $analytics = $run['analytics']['gsc'] ?? [];
        if (!empty($analytics)) {
            return [
                'clicks' => $analytics['clicks_30d'] ?? $analytics['clicks'] ?? null,
                'impressions' => $analytics['impressions_30d'] ?? $analytics['impressions'] ?? null,
                'ctr' => $analytics['ctr_30d'] ?? $analytics['ctr'] ?? null,
                'position' => $analytics['avg_position'] ?? $analytics['position'] ?? null,
            ];
        }

        return [
            'clicks' => null,
            'impressions' => null,
            'ctr' => null,
            'position' => null,
        ];
    }

    private static function buildSeverityCounts(array $issues): array
    {
        $counts = [];
        foreach ($issues as $issue) {
            $level = strtolower($issue['severity'] ?? 'other');
            $counts[$level] = ($counts[$level] ?? 0) + ((int) ($issue['occurrences'] ?? 0));
        }
        return $counts;
    }

    private static function collectGscQueries(array $run, int $limit = 5): array
    {
        $queries = self::getByPath($run, 'analytics.gsc_details.details.queries');
        if (!is_array($queries)) {
            return [];
        }
        usort($queries, static fn ($a, $b) => ($b['clicks'] ?? 0) <=> ($a['clicks'] ?? 0));
        return array_slice($queries, 0, $limit);
    }

    private static function formatDelta($current, $previous): string
    {
        if (!is_numeric($current) || !is_numeric($previous)) {
            return 'N/A';
        }
        $delta = (int) $current - (int) $previous;
        return ($delta >= 0 ? '+' : '') . $delta;
    }

    private static function formatTimeseriesDelta(array $series, string $field, bool $percent = false): string
    {
        $count = count($series);
        if ($count === 0) {
            return 'n/a';
        }
        $latest = $series[$count - 1];
        $previous = $series[$count - 2] ?? null;
        $current = isset($latest[$field]) ? (float) $latest[$field] : null;
        $previousValue = isset($previous[$field]) ? (float) $previous[$field] : null;

        $currentLabel = $current === null ? 'n/a' : ($percent ? self::formatPercent($current) : self::formatNumber($current, true));
        $previousLabel = $previousValue === null ? 'n/a' : ($percent ? self::formatPercent($previousValue) : self::formatNumber($previousValue, true));

        $deltaLabel = '';
        if ($current !== null && $previousValue !== null) {
            $delta = $current - $previousValue;
            $deltaLabel = 'Δ ' . ($delta >= 0 ? '+' : '') . number_format($delta, $percent ? 1 : 0) . ($percent ? '%' : '');
        }

        return 'Latest: ' . $currentLabel . ', Prev: ' . $previousLabel . ($deltaLabel !== '' ? ', ' . $deltaLabel : '');
    }

    private static function collectMetaRecommendations(array $run, int $limit = 5): array
    {
        $items = $run['items'] ?? [];
        $rows = [];
        foreach ($items as $item) {
            $ai = $item['ai'] ?? [];
            $metaRecommendation = $ai['meta_recommendation'] ?? $ai['recommendation'] ?? null;
            if (!is_array($metaRecommendation)) {
                continue;
            }
            $url = $item['url'] ?? '-';
            $meta = $item['meta'] ?? [];
            $rows[] = [
                'URL' => self::shortUrl($url),
                'Current Title' => self::safeText($meta['title'] ?? ''),
                'AI Title' => self::safeText($metaRecommendation['title'] ?? ''),
                'Current Meta' => self::safeText($meta['meta_description'] ?? ''),
                'AI Meta' => self::safeText($metaRecommendation['meta_description'] ?? $metaRecommendation['description'] ?? ''),
            ];
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    private static function safeText($value): string
    {
        $text = trim((string) $value);
        return $text !== '' ? $text : '-';
    }

    private static function formatMsAverage(array $values): string
    {
        if (empty($values)) {
            return 'N/A';
        }
        $avg = array_sum($values) / count($values);
        return number_format($avg, 0) . ' ms';
    }

    private static function emptyTable(string $message): array
    {
        return [
            'headers' => [],
            'rows' => [],
            'empty' => $message,
            'note' => '',
        ];
    }

    private static function normalizeTimeseries($timeseries): array
    {
        if (is_array($timeseries) && isset($timeseries['items']) && is_array($timeseries['items'])) {
            $timeseries = $timeseries['items'];
        }
        if (!is_array($timeseries)) {
            return [];
        }
        $out = [];
        foreach ($timeseries as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    private static function shortUrl(string $url, int $max = 60): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '-';
        }
        if (strlen($trimmed) <= $max) {
            return $trimmed;
        }
        return substr($trimmed, 0, $max - 1) . '…';
    }

    private static function formatNumber($value, bool $allowFloat = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return is_string($value) ? $value : null;
        }
        $number = $allowFloat ? (float) $value : (int) round($value);
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($number, $allowFloat ? 2 : 0);
        }
        return $allowFloat ? number_format($number, 2) : number_format((float) $number, 0);
    }

    private static function formatPercent($value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }
        $float = (float) $value;
        if ($float >= 0 && $float <= 1) {
            $float *= 100;
        }
        return number_format($float, 1) . '%';
    }

    private static function formatDateLabel(string $date): string
    {
        if ($date === '') {
            return '-';
        }
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return $date;
        }
        if (function_exists('wp_date')) {
            return wp_date('M j, Y', $timestamp);
        }
        return date('M j, Y', $timestamp);
    }

    private static function getByPath(array $data, string $path)
    {
        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}
