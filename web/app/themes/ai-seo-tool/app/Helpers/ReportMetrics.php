<?php
namespace AISEO\Helpers;

class ReportMetrics
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,array<string,mixed>>
     */
    public static function build(string $type, array $snapshot): array
    {
        $base = self::compileBase($snapshot);

        return [
            'executive_summary' => ['rows' => [], 'headers' => []],
            'top_actions' => ['rows' => [], 'headers' => []],
            'overview' => self::overviewTable($type, $base),
            'performance_summary' => self::performanceSummaryTable($type, $base),
            'technical_seo_issues' => self::technicalIssuesTable($type, $base),
            'onpage_seo_content' => self::onpageTable($base),
            'keyword_analysis' => self::keywordTable($base),
            'backlink_profile' => self::backlinkTable($base),
            'crawl_history' => self::crawlHistoryTable($base),
            'traffic_trends' => self::trafficTrendsTable($base),
            'search_visibility' => self::searchVisibilityTable($base),
            'meta_recommendations' => ['rows' => [], 'headers' => []],
            'technical_findings' => ['rows' => [], 'headers' => []],
            'recommendations' => ['rows' => [], 'headers' => []],
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function compileBase(array $snapshot): array
    {
        $runs = $snapshot['runs'] ?? [];

        $base = [
            'total_pages' => 0,
            'indexed_pages' => 0,
            'status' => [
                '2xx' => 0,
                '3xx' => 0,
                '4xx' => 0,
                '5xx' => 0,
            ],
            'issues' => [
                'broken_links' => 0,
                'missing_titles' => 0,
                'missing_meta' => 0,
                'long_titles' => 0,
                'low_content' => 0,
                'redirect_chains' => 0,
                'mixed_content' => 0,
                'duplicate_content' => 0,
                'missing_h1' => 0,
                'missing_canonical' => 0,
                'alt_missing' => 0,
            ],
            'samples' => [
                'broken_links' => null,
                'missing_titles' => null,
                'missing_meta' => null,
                'long_titles' => null,
                'low_content' => null,
                'redirect_chains' => null,
                'mixed_content' => null,
                'duplicate_content' => null,
                'missing_h1' => null,
                'missing_canonical' => null,
                'alt_missing' => null,
            ],
            'word_count_total' => 0,
            'word_count_pages' => 0,
            'avg_word_count' => 0,
            'pages' => [],
            'analytics' => [
                'ga_top_pageviews' => [],
                'ga_top_exit_pages' => [],
                'gsc_best_keywords' => [],
                'gsc_low_ctr' => [],
            ],
            'crawler' => [
                'largest_pages' => [],
                'slowest_pages' => [],
                'redirect_heavy' => [],
                'short_content_pages' => [],
            ],
            'backlinks' => [
                'referring_domains' => null,
                'total_backlinks' => null,
                'new_links' => null,
                'lost_links' => null,
                'toxic_score' => null,
                'anchor_distribution' => null,
                'last_synced' => null,
            ],
            'project_scope' => [
                'crawl_timeseries' => [],
                'ga_timeseries' => [],
                'gsc_timeseries' => [],
            ],
        ];

        $projectScope = is_array($snapshot['project_scope'] ?? null) ? $snapshot['project_scope'] : [];
        $base['project_scope']['crawl_timeseries'] = self::normalizeTimeseries($projectScope['timeseries'] ?? null);
        $base['project_scope']['ga_timeseries'] = self::normalizeTimeseries($projectScope['ga_timeseries'] ?? null);
        $base['project_scope']['gsc_timeseries'] = self::normalizeTimeseries($projectScope['gsc_timeseries'] ?? null);

        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $summary = is_array($run['summary'] ?? null) ? $run['summary'] : [];
            $audit = is_array($run['audit'] ?? null) ? $run['audit'] : [];
            $report = is_array($run['report'] ?? null) ? $run['report'] : [];
            $analytics = is_array($run['analytics'] ?? null) ? $run['analytics'] : [];
            $backlinks = is_array($run['backlinks'] ?? null) ? $run['backlinks'] : [];

            if ($analytics) {
                $existingAnalytics = $report['analytics'] ?? [];
                if (!is_array($existingAnalytics)) {
                    $existingAnalytics = [];
                }
                $report['analytics'] = array_merge($existingAnalytics, $analytics);
            }

            $statusSources = [
                $summary['status'] ?? null,
                self::getByPath($summary, 'status_buckets'),
                self::getByPath($audit, 'summary.status_buckets'),
                self::getByPath($report, 'crawl.status_buckets'),
            ];

            $statusBuckets = null;
            foreach ($statusSources as $source) {
                if (is_array($source) && !empty($source)) {
                    $statusBuckets = $source;
                    break;
                }
            }

            $totalPagesValue = $summary['pages']
                ?? $summary['total_pages']
                ?? self::getByPath($audit, 'summary.total_pages')
                ?? self::getByPath($report, 'crawl.pages_count')
                ?? 0;
            $base['total_pages'] += (int) $totalPagesValue;
            if ($statusBuckets) {
                foreach (['2xx', '3xx', '4xx', '5xx', 'other'] as $bucket) {
                    if (isset($statusBuckets[$bucket])) {
                        $base['status'][$bucket] += (int) $statusBuckets[$bucket];
                    }
                }
            }

            $runIssueCounts = array_fill_keys(array_keys($base['issues']), 0);
            $runIndexedPages = 0;

            $pages = $run['pages'] ?? [];
            if (!is_array($pages) || empty($pages)) {
                $pages = $report['pages'] ?? [];
            }
            if (!is_array($pages) || empty($pages)) {
                $pages = $audit['items'] ?? [];
            }
            if (!is_array($pages)) {
                $pages = [];
            }

            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }

                $base['pages'][] = $page;

                $url = trim((string) ($page['url'] ?? ''));
                $status = isset($page['status']) ? (int) $page['status'] : null;

                if ($status !== null) {
                    if ($status >= 200 && $status < 300) {
                        ++$runIndexedPages;
                    }
                    if ($status >= 400 && $status < 500) {
                        ++$runIssueCounts['broken_links'];
                        self::setSample($base['samples'], 'broken_links', $url);
                    }
                }

                $issuesList = $page['issues'] ?? [];
                if (is_array($issuesList) && !empty($issuesList)) {
                    foreach ($issuesList as $issueLabel) {
                        if (!is_string($issueLabel) || $issueLabel === '') {
                            continue;
                        }
                        $category = self::issueCategoryFromLabel($issueLabel);
                        if ($category && array_key_exists($category, $runIssueCounts)) {
                            ++$runIssueCounts[$category];
                            self::setSample($base['samples'], $category, $url);
                        }
                    }
                }

                if (array_key_exists('word_count', $page) || array_key_exists('words', $page)) {
                    $wordCount = (int) ($page['word_count'] ?? $page['words'] ?? 0);
                    if ($wordCount > 0) {
                        $base['word_count_total'] += $wordCount;
                        ++$base['word_count_pages'];
                    }
                    if ($wordCount > 0 && $wordCount < 300) {
                        ++$runIssueCounts['low_content'];
                        self::setSample($base['samples'], 'low_content', $url);
                    }
                }

                if (array_key_exists('title', $page)) {
                    $title = trim((string) $page['title']);
                    if ($title === '') {
                        ++$runIssueCounts['missing_titles'];
                        self::setSample($base['samples'], 'missing_titles', $url);
                    }
                    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
                    if ($titleLength > 65) {
                        ++$runIssueCounts['long_titles'];
                        self::setSample($base['samples'], 'long_titles', $url);
                    }
                }

                if (array_key_exists('meta_description', $page)) {
                    $meta = trim((string) $page['meta_description']);
                    if ($meta === '') {
                        ++$runIssueCounts['missing_meta'];
                        self::setSample($base['samples'], 'missing_meta', $url);
                    }
                }

                if (array_key_exists('canonical', $page)) {
                    $canonical = trim((string) $page['canonical']);
                    if ($canonical === '') {
                        ++$runIssueCounts['missing_canonical'];
                        self::setSample($base['samples'], 'missing_canonical', $url);
                    }
                }

                if (array_key_exists('h1', $page) || array_key_exists('h1_count', $page)) {
                    $h1 = $page['h1'] ?? null;
                    $h1Count = $page['h1_count'] ?? null;
                    $hasH1 = true;
                    if (is_string($h1)) {
                        $hasH1 = trim($h1) !== '';
                    } elseif (is_array($h1)) {
                        $hasH1 = !empty(array_filter($h1, static fn ($item) => trim((string) $item) !== ''));
                    } elseif ($h1Count !== null) {
                        $hasH1 = (int) $h1Count > 0;
                    }
                    if (!$hasH1) {
                        ++$runIssueCounts['missing_h1'];
                        self::setSample($base['samples'], 'missing_h1', $url);
                    }
                }

                if (array_key_exists('alt_missing', $page) || array_key_exists('images_missing_alt', $page)) {
                    $altMissing = $page['alt_missing'] ?? $page['images_missing_alt'] ?? null;
                    if (is_numeric($altMissing) && (int) $altMissing > 0) {
                        ++$runIssueCounts['alt_missing'];
                        self::setSample($base['samples'], 'alt_missing', $url);
                    }
                }

                if (array_key_exists('redirect_chain', $page) || array_key_exists('is_redirect_chain', $page)) {
                    $redirectFlag = $page['redirect_chain'] ?? $page['is_redirect_chain'] ?? null;
                    if (!empty($redirectFlag)) {
                        ++$runIssueCounts['redirect_chains'];
                        self::setSample($base['samples'], 'redirect_chains', $url);
                    }
                }

                if (array_key_exists('mixed_content', $page) || array_key_exists('has_mixed_content', $page)) {
                    $mixed = $page['mixed_content'] ?? $page['has_mixed_content'] ?? null;
                    if (!empty($mixed)) {
                        ++$runIssueCounts['mixed_content'];
                        self::setSample($base['samples'], 'mixed_content', $url);
                    }
                }

                if (array_key_exists('duplicate_content', $page) || array_key_exists('is_duplicate', $page)) {
                    $duplicate = $page['duplicate_content'] ?? $page['is_duplicate'] ?? null;
                    if (!empty($duplicate)) {
                        ++$runIssueCounts['duplicate_content'];
                        self::setSample($base['samples'], 'duplicate_content', $url);
                    }
                }
            }

            if ($runIndexedPages === 0 && $statusBuckets && isset($statusBuckets['2xx'])) {
                $runIndexedPages = (int) $statusBuckets['2xx'];
            }
            $base['indexed_pages'] += $runIndexedPages;
            if ($statusBuckets && isset($statusBuckets['4xx'])) {
                $runIssueCounts['broken_links'] = max($runIssueCounts['broken_links'], (int) $statusBuckets['4xx']);
            }

            $issueSummary = $summary['issue_counts'] ?? null;
            if (!is_array($issueSummary)) {
                $issueSummary = self::getByPath($audit, 'summary.issue_counts');
            }
            if (!is_array($issueSummary)) {
                $issueSummary = self::getByPath($report, 'audit.summary.issue_counts');
            }
            if (!is_array($issueSummary)) {
                $issueSummary = [];
            }
            foreach ($issueSummary as $label => $count) {
                if (!is_string($label)) {
                    continue;
                }
                $category = self::issueCategoryFromLabel($label);
                if ($category && array_key_exists($category, $runIssueCounts)) {
                    $runIssueCounts[$category] = max($runIssueCounts[$category], (int) $count);
                }
            }

            foreach ($runIssueCounts as $key => $count) {
                $base['issues'][$key] += $count;
            }

            if ($report) {
                self::mergeLists($base['analytics']['ga_top_pageviews'], self::extractList($report, [
                    'ga4.top_pageviews',
                    'ga.top_pageviews',
                    'analytics.top_pageviews',
                    'analytics.ga.top_pages',
                    'analytics.ga.top_pageviews',
                ]));
                self::mergeLists($base['analytics']['ga_top_exit_pages'], self::extractList($report, [
                    'ga4.top_exit_pages',
                    'ga.top_exit_pages',
                    'analytics.top_exit_pages',
                    'analytics.ga.top_exit_pages',
                ]));
                self::mergeLists($base['analytics']['gsc_best_keywords'], self::extractList($report, [
                    'gsc.best_keywords',
                    'gsc.best_performing_keywords',
                    'analytics.gsc.top_keywords',
                    'analytics.gsc.best_keywords',
                ]));
                self::mergeLists($base['analytics']['gsc_low_ctr'], self::extractList($report, [
                    'gsc.low_ctr_keywords',
                    'gsc.lowest_ctr',
                    'analytics.gsc.low_ctr_keywords',
                    'analytics.gsc.low_ctr',
                ]));
                self::mergeLists($base['crawler']['largest_pages'], self::extractList($report, [
                    'crawler.largest_pages',
                    'crawler.pages_largest',
                ]));
                self::mergeLists($base['crawler']['slowest_pages'], self::extractList($report, [
                    'crawler.slowest_pages',
                    'crawler.pages_slowest',
                ]));
                self::mergeLists($base['crawler']['redirect_heavy'], self::extractList($report, [
                    'crawler.redirect_heavy',
                    'crawler.most_redirects',
                ]));
                self::mergeLists($base['crawler']['short_content_pages'], self::extractList($report, [
                    'crawler.short_content_pages',
                    'crawler.thin_content_pages',
                ]));
            }

            $gaData = $analytics['ga'] ?? null;
            if (is_array($gaData)) {
                if (isset($gaData['top_pages']) && is_array($gaData['top_pages'])) {
                    self::mergeLists($base['analytics']['ga_top_pageviews'], $gaData['top_pages']);
                }
                if (isset($gaData['top_exit_pages']) && is_array($gaData['top_exit_pages'])) {
                    self::mergeLists($base['analytics']['ga_top_exit_pages'], $gaData['top_exit_pages']);
                }
            }

            $gscData = $analytics['gsc'] ?? null;
            if (is_array($gscData)) {
                if (isset($gscData['top_keywords']) && is_array($gscData['top_keywords'])) {
                    self::mergeLists($base['analytics']['gsc_best_keywords'], $gscData['top_keywords']);
                }
                if (isset($gscData['low_ctr_keywords']) && is_array($gscData['low_ctr_keywords'])) {
                    self::mergeLists($base['analytics']['gsc_low_ctr'], $gscData['low_ctr_keywords']);
                }
            }

            $gscDetails = self::getByPath($analytics, 'gsc_details.details.queries');
            if (is_array($gscDetails) && !empty($gscDetails)) {
                $normalizedQueries = array_map(static function ($row) {
                    if (!is_array($row)) {
                        return [];
                    }
                    return [
                        'keyword' => $row['key'] ?? ($row['keyword'] ?? ''),
                        'clicks' => $row['clicks'] ?? null,
                        'ctr' => $row['ctr'] ?? null,
                        'position' => $row['position'] ?? null,
                    ];
                }, $gscDetails);
                self::mergeLists($base['analytics']['gsc_best_keywords'], array_filter($normalizedQueries));
            }

            $backlinkProvider = $backlinks['provider'] ?? null;
            if (is_array($backlinkProvider)) {
                $base['backlinks']['referring_domains'] = $base['backlinks']['referring_domains'] ?? ($backlinkProvider['referring_domains'] ?? null);
                $base['backlinks']['total_backlinks'] = $base['backlinks']['total_backlinks'] ?? ($backlinkProvider['total_backlinks'] ?? null);
                $base['backlinks']['new_links'] = $base['backlinks']['new_links'] ?? ($backlinkProvider['new_links'] ?? null);
                $base['backlinks']['lost_links'] = $base['backlinks']['lost_links'] ?? ($backlinkProvider['lost_links'] ?? null);
                $base['backlinks']['toxic_score'] = $base['backlinks']['toxic_score'] ?? ($backlinkProvider['toxic_score'] ?? null);
                $base['backlinks']['anchor_distribution'] = $base['backlinks']['anchor_distribution'] ?? ($backlinkProvider['anchor_distribution'] ?? $backlinkProvider['anchor_text_distribution'] ?? null);
                $base['backlinks']['last_synced'] = $base['backlinks']['last_synced'] ?? ($backlinkProvider['last_synced'] ?? null);
            }
        }

        if ($base['word_count_pages'] > 0) {
            $base['avg_word_count'] = (int) round($base['word_count_total'] / $base['word_count_pages']);
        }

        return $base;
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function overviewTable(string $type, array $base): array
    {
        $rows = [];

        if ($type === 'technical') {
            $rows = [
                ['Metric' => 'Total Pages Crawled', 'Value' => self::formatNumber($base['total_pages'])],
                ['Metric' => 'Indexed Pages (2xx)', 'Value' => self::formatNumber($base['status']['2xx'])],
                ['Metric' => 'Broken Links (4xx)', 'Value' => self::formatNumber($base['status']['4xx'])],
                ['Metric' => 'Missing Titles', 'Value' => self::formatNumber($base['issues']['missing_titles'])],
                ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($base['issues']['missing_meta'])],
                ['Metric' => 'Redirect Chains', 'Value' => self::formatNumber($base['issues']['redirect_chains'])],
                ['Metric' => 'Mixed Content Pages', 'Value' => self::formatNumber($base['issues']['mixed_content'])],
            ];
        } elseif ($type === 'per_page') { // using per_page as content audit
            $rows = [
                ['Metric' => 'Total Pages Crawled', 'Value' => self::formatNumber($base['total_pages'])],
                ['Metric' => 'Indexed Pages (2xx)', 'Value' => self::formatNumber($base['status']['2xx'])],
                ['Metric' => 'Average Word Count', 'Value' => self::formatNumber($base['avg_word_count'])],
                ['Metric' => 'Pages <300 Words', 'Value' => self::formatNumber($base['issues']['low_content'])],
                ['Metric' => 'Duplicate Content Issues', 'Value' => self::formatNumber($base['issues']['duplicate_content'])],
                ['Metric' => 'Missing Titles', 'Value' => self::formatNumber($base['issues']['missing_titles'])],
                ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($base['issues']['missing_meta'])],
            ];
        } else {
            $rows = [
                ['Metric' => 'Total Pages Crawled', 'Value' => self::formatNumber($base['total_pages'])],
                ['Metric' => 'Indexed Pages (2xx)', 'Value' => self::formatNumber($base['status']['2xx'])],
                ['Metric' => 'Average Word Count', 'Value' => self::formatNumber($base['avg_word_count'])],
                ['Metric' => 'Broken Links (4xx)', 'Value' => self::formatNumber($base['status']['4xx'])],
                ['Metric' => 'Missing Titles', 'Value' => self::formatNumber($base['issues']['missing_titles'])],
                ['Metric' => 'Missing Meta Descriptions', 'Value' => self::formatNumber($base['issues']['missing_meta'])],
                ['Metric' => 'Long Titles (>65 chars)', 'Value' => self::formatNumber($base['issues']['long_titles'])],
                ['Metric' => 'Pages <300 Words', 'Value' => self::formatNumber($base['issues']['low_content'])],
            ];
        }

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'empty' => $base['total_pages'] ? null : 'Metrics will populate after the first crawler run.',
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function performanceSummaryTable(string $type, array $base): array
    {
        $rows = [];

        if ($type === 'technical') {
            $rows[] = [
                'Source' => 'Google Search Console',
                'Metric' => 'Top Crawl Errors',
                'Top Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['error', 'keyword', 'term'], 'Connect Google Search Console'),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Slowest Page',
                'Top Value' => self::firstValue($base['crawler']['slowest_pages'], ['url', 'page'], self::fallbackSlowestPage($base)),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Most Redirects',
                'Top Value' => self::firstValue($base['crawler']['redirect_heavy'], ['url', 'page'], self::fallbackRedirectHeavyPage($base)),
            ];
        } elseif ($type === 'per_page') {
            $rows[] = [
                'Source' => 'Google Analytics',
                'Metric' => 'Top Exit Page',
                'Top Value' => self::firstValue($base['analytics']['ga_top_exit_pages'], ['url', 'page'], 'Connect Google Analytics'),
            ];
            $rows[] = [
                'Source' => 'Google Search Console',
                'Metric' => 'Lowest CTR Keyword',
                'Top Value' => self::firstValue($base['analytics']['gsc_low_ctr'], ['keyword', 'term'], 'Connect Google Search Console'),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Shortest Content Page',
                'Top Value' => self::firstValue($base['crawler']['short_content_pages'], ['url', 'page'], self::fallbackShortContentPage($base)),
            ];
        } else {
            $rows[] = [
                'Source' => 'Google Analytics',
                'Metric' => 'Top Pageviews',
                'Top Value' => self::firstValue($base['analytics']['ga_top_pageviews'], ['url', 'page'], 'Connect Google Analytics'),
            ];
            $rows[] = [
                'Source' => 'Google Search Console',
                'Metric' => 'Best Performing Keyword',
                'Top Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['keyword', 'term'], 'Connect Google Search Console'),
            ];
            $rows[] = [
                'Source' => 'Crawler',
                'Metric' => 'Largest Page',
                'Top Value' => self::firstValue($base['crawler']['largest_pages'], ['url', 'page'], self::fallbackLargestPage($base)),
            ];
        }

        return [
            'headers' => ['Source', 'Metric', 'Top Value'],
            'rows' => $rows,
            'note' => empty($base['analytics']['ga_top_pageviews']) && empty($base['analytics']['gsc_best_keywords'])
                ? 'Connect analytics data sources to enrich this summary.'
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function technicalIssuesTable(string $type, array $base): array
    {
        $rows = [];

        if ($type === 'per_page') {
            $rows[] = self::issueRow('Duplicate Content', $base['issues']['duplicate_content'], $base['samples']['duplicate_content']);
            $rows[] = self::issueRow('Thin Content (<300 words)', $base['issues']['low_content'], $base['samples']['low_content']);
            $rows[] = self::issueRow('Missing Titles', $base['issues']['missing_titles'], $base['samples']['missing_titles']);
            $rows[] = self::issueRow('Missing Meta Descriptions', $base['issues']['missing_meta'], $base['samples']['missing_meta']);
            $rows[] = self::issueRow('Images Missing ALT', $base['issues']['alt_missing'], $base['samples']['alt_missing']);
        } else {
            $rows[] = self::issueRow('Broken Links (4xx)', $base['status']['4xx'], $base['samples']['broken_links']);
            $rows[] = self::issueRow('Missing Canonical', $base['issues']['missing_canonical'], $base['samples']['missing_canonical']);
            $rows[] = self::issueRow('Redirect Chains', $base['issues']['redirect_chains'], $base['samples']['redirect_chains']);
            $rows[] = self::issueRow('Missing H1', $base['issues']['missing_h1'], $base['samples']['missing_h1']);
            $rows[] = self::issueRow('HTTPS / Mixed Content', $base['issues']['mixed_content'], $base['samples']['mixed_content']);
        }

        return [
            'headers' => ['Issue Type', 'Count', 'Example URL'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function onpageTable(array $base): array
    {
        $rows = [
            [
                'Element' => 'Title',
                'Issue' => self::formatIssueCountDetail('Missing or length issues', $base['issues']['missing_titles'] + $base['issues']['long_titles']),
                'Recommendation' => 'Keep unique titles under ~65 characters.',
            ],
            [
                'Element' => 'Meta Description',
                'Issue' => self::formatIssueCountDetail('Missing descriptions', $base['issues']['missing_meta']),
                'Recommendation' => 'Add intent-driven copy around 150–160 characters.',
            ],
            [
                'Element' => 'Headings',
                'Issue' => self::formatIssueCountDetail('Missing primary H1', $base['issues']['missing_h1']),
                'Recommendation' => 'Ensure a single descriptive H1 per page.',
            ],
            [
                'Element' => 'Word Count',
                'Issue' => self::formatIssueCountDetail('Pages under 300 words', $base['issues']['low_content']),
                'Recommendation' => 'Expand content with supporting detail and internal links.',
            ],
            [
                'Element' => 'Images',
                'Issue' => self::formatIssueCountDetail('Missing ALT attributes', $base['issues']['alt_missing']),
                'Recommendation' => 'Add descriptive ALT text to key imagery.',
            ],
        ];

        return [
            'headers' => ['Element', 'Common Issue', 'Recommendation'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function keywordTable(array $base): array
    {
        $rows = [
            [
                'Metric' => 'Top keywords by impressions',
                'Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['keyword', 'term'], 'Connect Google Search Console'),
            ],
            [
                'Metric' => 'Top keywords by clicks',
                'Value' => self::firstValue($base['analytics']['gsc_best_keywords'], ['keyword', 'term'], 'Connect Google Search Console', 1),
            ],
            [
                'Metric' => 'Keywords with low CTR',
                'Value' => self::firstValue($base['analytics']['gsc_low_ctr'], ['keyword', 'term'], 'Connect Google Search Console'),
            ],
            [
                'Metric' => 'High impressions, low position',
                'Value' => self::firstValue($base['analytics']['gsc_low_ctr'], ['keyword', 'term'], 'Add GSC data to highlight opportunities', 1),
            ],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function backlinkTable(array $base): array
    {
        $rows = [
            ['Metric' => 'Referring Domains', 'Value' => self::formatNumber($base['backlinks']['referring_domains']) ?? 'Connect a backlink provider'],
            ['Metric' => 'Total Backlinks', 'Value' => self::formatNumber($base['backlinks']['total_backlinks']) ?? '-'],
            ['Metric' => 'New Links', 'Value' => self::formatNumber($base['backlinks']['new_links']) ?? '-'],
            ['Metric' => 'Lost Links', 'Value' => self::formatNumber($base['backlinks']['lost_links']) ?? '-'],
            ['Metric' => 'Average Toxic Score', 'Value' => self::formatNumber($base['backlinks']['toxic_score'], true) ?? '-'],
        ];

        return [
            'headers' => ['Metric', 'Value'],
            'rows' => $rows,
            'note' => $base['backlinks']['anchor_distribution'] ? null : 'Import backlink data (Ahrefs, SEMrush, etc.) for richer insights.',
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function crawlHistoryTable(array $base): array
    {
        $series = array_reverse($base['project_scope']['crawl_timeseries']);
        $series = array_slice($series, 0, 6);

        $rows = [];
        foreach ($series as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Date' => self::formatDateLabel((string) ($entry['date'] ?? '')),
                'Pages' => self::formatNumber($entry['pages'] ?? null) ?? '-',
                'Issues' => self::formatNumber($entry['issues'] ?? null) ?? '-',
                '4xx' => self::formatNumber($entry['4xx'] ?? $entry['errors'] ?? null) ?? '-',
            ];
        }

        return [
            'headers' => ['Date', 'Pages', 'Issues', '4xx'],
            'rows' => $rows,
            'empty' => $rows ? null : 'Refresh data to build crawl history.',
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function trafficTrendsTable(array $base): array
    {
        $series = array_reverse($base['project_scope']['ga_timeseries']);
        $series = array_slice($series, 0, 6);

        $rows = [];
        foreach ($series as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Date' => self::formatDateLabel((string) ($entry['date'] ?? '')),
                'Sessions' => self::formatNumber($entry['sessions'] ?? null) ?? '-',
                'Users' => self::formatNumber($entry['totalUsers'] ?? ($entry['users'] ?? null)) ?? '-',
                'Pageviews' => self::formatNumber($entry['screenPageViews'] ?? ($entry['pageviews'] ?? null)) ?? '-',
            ];
        }

        return [
            'headers' => ['Date', 'Sessions', 'Users', 'Pageviews'],
            'rows' => $rows,
            'empty' => $rows ? null : 'Connect Google Analytics and refresh to populate traffic trends.',
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    private static function searchVisibilityTable(array $base): array
    {
        $series = array_reverse($base['project_scope']['gsc_timeseries']);
        $series = array_slice($series, 0, 6);

        $rows = [];
        foreach ($series as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $rows[] = [
                'Date' => self::formatDateLabel((string) ($entry['date'] ?? '')),
                'Clicks' => self::formatNumber($entry['clicks'] ?? null) ?? '-',
                'Impressions' => self::formatNumber($entry['impressions'] ?? null) ?? '-',
                'CTR' => self::formatPercent($entry['ctr'] ?? null) ?? '-',
                'Avg Position' => self::formatNumber($entry['position'] ?? null, true) ?? '-',
            ];
        }

        return [
            'headers' => ['Date', 'Clicks', 'Impressions', 'CTR', 'Avg Position'],
            'rows' => $rows,
            'empty' => $rows ? null : 'Connect Google Search Console and refresh to populate search trends.',
        ];
    }

    /**
     * @param array<string,int|string|null> $samples
     */
    private static function setSample(array &$samples, string $key, ?string $value): void
    {
        if ($value && empty($samples[$key])) {
            $samples[$key] = $value;
        }
    }

    private static function issueCategoryFromLabel(string $label): ?string
    {
        $needle = strtolower($label);
        $map = [
            'broken_links' => ['broken link', '404', '4xx', 'link error'],
            'missing_titles' => ['missing title', 'no title'],
            'missing_meta' => ['missing meta description', 'no meta description'],
            'long_titles' => ['title too long', 'long title', 'title length'],
            'low_content' => ['thin content', 'low word count', 'insufficient content', 'under 300 words', 'short content'],
            'redirect_chains' => ['redirect chain', 'too many redirects', 'redirect loop'],
            'mixed_content' => ['mixed content'],
            'duplicate_content' => ['duplicate content', 'duplicate page'],
            'missing_h1' => ['missing h1'],
            'missing_canonical' => ['missing canonical'],
            'alt_missing' => ['missing alt', 'without alt', 'no alt text'],
        ];

        foreach ($map as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int,mixed> $target
     * @param array<int,mixed> $source
     */
    private static function mergeLists(array &$target, array $source): void
    {
        foreach ($source as $item) {
            $target[] = $item;
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string> $paths
     * @return array<int,mixed>
     */
    private static function extractList(array $data, array $paths): array
    {
        foreach ($paths as $path) {
            $value = self::getByPath($data, $path);
            if (is_array($value) && !empty($value)) {
                return array_values($value);
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $data
     */
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

    /**
     * @return array<string,string>
     */
    private static function issueRow(string $label, int $count, ?string $sample): array
    {
        return [
            'Issue Type' => $label,
            'Count' => self::formatNumber($count),
            'Example URL' => $sample ? self::shortUrl($sample) : '-',
        ];
    }

    private static function formatIssueCountDetail(string $label, int $count): string
    {
        $number = self::formatNumber($count);
        return $number . ' ' . strtolower($label);
    }

    private static function formatNumber($value, bool $allowFloat = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return is_string($value) ? $value : null;
        }
        $number = $allowFloat ? (float) $value : (int) $value;
        if (function_exists('number_format_i18n')) {
            return number_format_i18n($number, $allowFloat ? 2 : 0);
        }
        return $allowFloat ? number_format($number, 2) : number_format((float) $number, 0);
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

    /**
     * @param array<int,mixed> $list
     */
    private static function firstValue(array $list, array $keys, string $fallback, int $offset = 0): string
    {
        if (isset($list[$offset])) {
            $item = $list[$offset];
            if (is_string($item) && $item !== '') {
                return $item;
            }
            if (is_array($item)) {
                foreach ($keys as $key) {
                    if (!empty($item[$key])) {
                        return (string) $item[$key];
                    }
                }
                if (!empty($item['value'])) {
                    return (string) $item['value'];
                }
            }
        }
        if (!empty($list)) {
            $item = $list[0];
            if (is_string($item) && $item !== '') {
                return $item;
            }
            if (is_array($item)) {
                foreach ($keys as $key) {
                    if (!empty($item[$key])) {
                        return (string) $item[$key];
                    }
                }
            }
        }

        return $fallback;
    }

    /**
     * @param mixed $timeseries
     * @return array<int,array<string,mixed>>
     */
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

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackLargestPage(array $base): string
    {
        $best = null;
        foreach ($base['pages'] as $page) {
            $wordCount = (int) ($page['word_count'] ?? 0);
            if ($wordCount <= 0) {
                continue;
            }
            if (!$best || $wordCount > $best['word_count']) {
                $best = [
                    'url' => $page['url'] ?? '',
                    'word_count' => $wordCount,
                ];
            }
        }
        if ($best) {
            return self::shortUrl((string) $best['url']) . ' (' . self::formatNumber($best['word_count']) . ' words)';
        }
        return 'Crawler data required';
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackSlowestPage(array $base): string
    {
        $best = null;
        foreach ($base['pages'] as $page) {
            $time = $page['load_time_ms'] ?? $page['load_time'] ?? $page['response_time'] ?? null;
            if (!is_numeric($time)) {
                continue;
            }
            $time = (float) $time;
            if (!$best || $time > $best['time']) {
                $best = [
                    'url' => $page['url'] ?? '',
                    'time' => $time,
                ];
            }
        }

        if ($best) {
            $timeLabel = $best['time'] >= 1000 ? round($best['time'] / 1000, 2) . 's' : round($best['time'], 0) . 'ms';
            return self::shortUrl((string) $best['url']) . ' (' . $timeLabel . ')';
        }

        return 'Crawler timing data needed';
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackRedirectHeavyPage(array $base): string
    {
        if (!empty($base['samples']['redirect_chains'])) {
            return self::shortUrl((string) $base['samples']['redirect_chains']);
        }
        return 'No redirect chains detected';
    }

    /**
     * @param array<string,mixed> $base
     */
    private static function fallbackShortContentPage(array $base): string
    {
        if (!empty($base['samples']['low_content'])) {
            return self::shortUrl((string) $base['samples']['low_content']);
        }
        return 'All pages exceed 300 words';
    }
}
